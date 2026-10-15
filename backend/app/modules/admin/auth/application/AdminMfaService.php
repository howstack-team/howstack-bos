<?php

declare(strict_types=1);
namespace app\modules\admin\auth\application;
use app\exception\BusinessException;
use app\modules\admin\auth\contract\{AdminMfaRepositoryInterface, AdminAccountRepositoryInterface, TotpInterface, AdminLoginRateLimiterInterface};
use app\modules\admin\auth\domain\{AdminAccount, AdminMfaLoginChallenge};
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\http\ApiErrorCode;

/** 管理员MFA用例；每次验证均在账户锁内消费时间步或恢复码。 */
final readonly class AdminMfaService
{
    public function __construct(private AdminMfaRepositoryInterface $repository, private AdminAccountRepositoryInterface $accounts,
        private TotpInterface $totp, private SensitiveDataCipherInterface $cipher, private RecordAdminLoginService $logs, private AdminLoginRateLimiterInterface $limiter) {}

    /** @return array{enabled:bool, enabled_at:?string, recovery_remaining:int} */
    public function status(int $id): array
    {
        $state = $this->repository->find($id);
        return ['enabled' => !empty($state['secret_encrypted']), 'enabled_at' => $state['enabled_at'] ?? null,
            'recovery_remaining' => count(json_decode($state['recovery_hashes'] ?? '[]', true, 32, JSON_THROW_ON_ERROR))];
    }
    public function challenge(AdminAccount $actor, bool $remember): ?AdminMfaLoginChallenge
    {
        return $this->repository->locked($actor->id, function (array $state) use ($actor, $remember) {
            $live = $this->current($actor);
            if (empty($state['secret_encrypted'])) return null;
            $this->throttle('issue:' . $actor->id, 5);
            $token = bin2hex(random_bytes(32));
            $expiry = time() + 300;
            $this->repository->createChallenge(['token_hash' => hash('sha256', $token), 'admin_user_id' => $actor->id,
                'credential_version' => $this->version($live), 'remember' => $remember, 'attempts' => 0,
                'expires_at' => date('Y-m-d H:i:s', $expiry), 'created_at' => date('Y-m-d H:i:s')]);
            return new AdminMfaLoginChallenge($token, $expiry);
        });
    }
    /** @return array{account:AdminAccount,remember:bool} */
    public function verifyLogin(string $token, string $code, bool $recovery, string $ip = '127.0.0.1', string $userAgent = ''): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) $this->expired();
        $hash = hash('sha256', $token);
        $challenge = $this->repository->challenge($hash);
        if ($challenge === null) $this->expired();
        $this->throttle('verify:' . $challenge['admin_user_id'], 10);
        $result = $this->repository->locked((int) $challenge['admin_user_id'], function (array $state) use ($hash, $code, $recovery) {
            $row = $this->repository->challenge($hash);
            if ($row === null || (int) $row['attempts'] >= 5 || $row['expires_at'] <= date('Y-m-d H:i:s') || empty($state['secret_encrypted'])) return null;
            $account = $this->accounts->findById((int) $row['admin_user_id']);
            if ($account === null || !$account->isActive() || !hash_equals($row['credential_version'], $this->version($account))) return null;
            if (!$this->consume($account->id, $state, $code, $recovery)) {
                $this->repository->attempt($hash, false);
                return false; // 提交失败次数后，才在事务外抛出错误。
            }
            $this->repository->attempt($hash, true);
            return ['account' => $account, 'remember' => (bool) $row['remember']];
        });
        if ($result === null || $result === false) {
            $account = $this->accounts->findById((int) $challenge['admin_user_id']);
            $this->logs->record($account, $account?->username ?? '', false, $ip, $userAgent, $result === false ? 'mfa_invalid' : 'mfa_expired');
        }
        if ($result === null) $this->expired();
        if ($result === false) $this->field('code', '验证码或恢复码不正确、已使用或已过期');
        return $result;
    }
    /** @return array{setup_id:string, secret:string, uri:string, expires_at:int} */
    public function begin(AdminAccount $actor, string $password): array
    {
        return $this->repository->locked($actor->id, function (array $state) use ($actor, $password) {
            $this->password($this->current($actor), $password);
            if (!empty($state['secret_encrypted'])) throw new BusinessException('已绑定验证器，请先关闭后重新绑定', ApiErrorCode::CONFLICT, 409);
            $secret = $this->totp->generate();
            $setupId = bin2hex(random_bytes(32));
            $expiry = time() + 600;
            $this->repository->save($actor->id, ['pending_encrypted' => $this->cipher->encrypt($secret), 'setup_hash' => hash('sha256', $setupId), 'setup_expires_at' => date('Y-m-d H:i:s', $expiry)]);
            return ['setup_id' => $setupId, 'secret' => $secret, 'uri' => $this->totp->uri($secret, $actor->username), 'expires_at' => $expiry];
        });
    }
    /** @return list<string> 新恢复码，仅返回一次 */
    public function confirm(AdminAccount $actor, string $password, string $setupId, string $code): array
    {
        return $this->repository->locked($actor->id, function (array $state) use ($actor, $password, $setupId, $code) {
            $this->password($this->current($actor), $password);
            if (!empty($state['secret_encrypted']) || empty($state['pending_encrypted']) || $state['setup_expires_at'] <= date('Y-m-d H:i:s')
                || !hash_equals($state['setup_hash'] ?? '', hash('sha256', $setupId))) throw new BusinessException('绑定已失效，请重新开始', ApiErrorCode::CONFLICT, 409);
            $step = $this->totp->counter($this->cipher->decrypt($state['pending_encrypted']), $code, -1, time());
            if ($step === null) $this->field('code', '验证码不正确，请检查验证器及设备时间');
            $codes = $this->codes();
            $this->repository->save($actor->id, ['secret_encrypted' => $state['pending_encrypted'], 'pending_encrypted' => null,
                'setup_hash' => null, 'setup_expires_at' => null, 'last_counter' => $step, 'recovery_hashes' => $this->hashes($codes), 'enabled_at' => date('Y-m-d H:i:s')], true);
            return $codes;
        });
    }
    /** 关闭与重置恢复码均要求当前密码和有效的第二因素。 @return list<string> */
    public function change(AdminAccount $actor, string $password, string $code, bool $recovery, bool $disable): array
    {
        $result = $this->repository->locked($actor->id, function (array $state) use ($actor, $password, $code, $recovery, $disable) {
            $this->password($this->current($actor), $password);
            if (empty($state['secret_encrypted'])) throw new BusinessException('尚未开启两步验证', ApiErrorCode::CONFLICT, 409);
            if (!$this->consume($actor->id, $state, $code, $recovery)) return false;
            $codes = $disable ? [] : $this->codes();
            $values = ['recovery_hashes' => $this->hashes($codes)];
            if ($disable) $values += ['secret_encrypted' => null, 'pending_encrypted' => null, 'setup_hash' => null, 'setup_expires_at' => null, 'last_counter' => -1, 'enabled_at' => null];
            $this->repository->save($actor->id, $values, true);
            return $codes;
        });
        if ($result === false) $this->field('code', '验证码或恢复码不正确、已使用或已过期');
        return $result;
    }
    private function consume(int $id, array $state, string $code, bool $recovery): bool
    {
        if ($recovery) {
            $hashes = json_decode($state['recovery_hashes'], true, 32, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', strtolower(str_replace('-', '', $code)));
            foreach ($hashes as $index => $candidate) {
                if (!hash_equals($candidate, $hash)) continue;
                unset($hashes[$index]);
                $this->repository->save($id, ['recovery_hashes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR)]);
                return true;
            }
            return false;
        }
        $counter = $this->totp->counter($this->cipher->decrypt($state['secret_encrypted']), $code, (int) $state['last_counter'], time());
        if ($counter === null) return false;
        $this->repository->save($id, ['last_counter' => $counter]);
        return true;
    }
    private function current(AdminAccount $actor): AdminAccount
    {
        $live = $this->accounts->findById($actor->id);
        if ($live === null || !$live->isActive() || !hash_equals($this->version($live), $this->version($actor))) throw AdminAuthenticationException::invalidToken();
        return $live;
    }
    private function password(AdminAccount $actor, string $password): void
    {
        if (!password_verify($password, $actor->passwordHash)) $this->field('current_password', '当前密码不正确');
    }
    private function version(AdminAccount $actor): string { return hash('sha256', $actor->passwordHash . ':' . $actor->authVersion); }
    /** @return list<string> 10组各128位随机恢复码 */
    private function codes(): array { return array_map(static fn () => implode('-', str_split(bin2hex(random_bytes(16)), 8)), range(1, 10)); }
    private function hashes(array $codes): string { return json_encode(array_map(static fn (string $v) => hash('sha256', str_replace('-', '', $v)), $codes), JSON_THROW_ON_ERROR); }
    private function throttle(string $key, int $limit): void
    {
        if (!$this->limiter->allows('admin-mfa:' . $key, $limit, 300)) throw new BusinessException('两步验证尝试过于频繁，请5分钟后重试', ApiErrorCode::TOO_MANY_REQUESTS, 429);
    }
    private function expired(): never { throw new BusinessException('验证已失效，请重新输入账号密码', ApiErrorCode::UNAUTHENTICATED, 401); }
    private function field(string $field, string $message): never { throw new BusinessException('请检查填写内容', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => [$message]]]); }
}
