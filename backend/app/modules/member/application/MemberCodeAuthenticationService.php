<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\member\contract\{MemberAuthRepositoryInterface, MemberAuthChallengeRepositoryInterface, MemberTokenServiceInterface, RegistrationRateLimiterInterface};
use app\shared\http\ApiErrorCode;

final readonly class MemberCodeAuthenticationService
{
    public function __construct(private MemberAuthRepositoryInterface $members,
        private MemberAuthChallengeRepositoryInterface $challenges, private MemberTokenServiceInterface $tokens,
        private VerificationMessageSender $messages, private RegistrationRateLimiterInterface $limiter,
        private string $secret)
    {
    }

    /** @return array{sms_login: bool, password_reset: array{mobile: bool, email: bool}, oauth_providers: list<string>} */
    public function options(): array
    {
        return ['sms_login' => $this->messages->configuration('login_verification', 'mobile') !== null,
            'password_reset' => ['mobile' => $this->messages->configuration('password_reset', 'mobile') !== null,
                'email' => $this->messages->configuration('password_reset', 'email') !== null],
            // HTTP认证入口按实际已启用的第三方通道补充此目录。
            'oauth_providers' => []];
    }

    /** @return array{challenge_id: string, expires_in: int, retry_after: int} */
    public function send(string $purpose, string $target, string $binding, string $ip): array
    {
        $method = str_contains($target, '@') ? 'email' : 'mobile';
        $scene = $purpose === 'login' ? 'login_verification' : 'password_reset';
        if (!in_array($purpose, ['login', 'password_reset'], true) || ($purpose === 'login' && $method !== 'mobile')) {
            throw new BusinessException('验证码用途无效', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $this->limiter->check('code', $purpose . ':' . $method . ':' . $target, $ip);
        $config = $this->messages->configuration($scene, $method);
        if ($config === null) throw new BusinessException('此验证方式暂不可用，请联系管理员', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        $member = $method === 'email' ? $this->members->findByEmail($target) : $this->members->findByMobile($target);
        $eligible = $member !== null && $member->status === 1;
        $id = bin2hex(random_bytes(16));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->challenges->issue(['challenge_id' => $id, 'purpose' => $purpose, 'method' => $method,
            'member_id' => $eligible ? $member->id : null, 'password_version' => $eligible ? hash('sha256', $member->passwordHash) : hash('sha256', random_bytes(32)),
            'target_hash' => $this->digest($purpose . ':' . $method . ':' . $target), 'binding_hash' => $this->digest($binding),
            'code_hash' => $this->digest($id . ':' . $code), 'status' => 'pending', 'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 300), 'created_at' => date('Y-m-d H:i:s')]);
        $accepted = $this->messages->send($scene, $method, $target, $id, $code, $config);
        $this->challenges->activate($id, $accepted);
        // 未注册和停用账号也走相同投递路径，验证码不能创建账号或绕过会员状态。
        if (!$accepted) throw new BusinessException('验证码发送失败，请稍后重试', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        return ['challenge_id' => $id, 'expires_in' => 300, 'retry_after' => 60];
    }

    /** @return array{user: array<string, mixed>, token: string} */
    public function login(string $target, string $id, string $code, string $binding, string $ip, bool $remember): array
    {
        $this->limiter->check('verify', 'login:' . $target, $ip);
        $member = $this->challenges->consume($id, 'login', 'mobile', $target,
            $this->digest('login:mobile:' . $target), $this->digest($binding), $this->digest($id . ':' . $code));
        if ($member === null) throw $this->invalidCode();
        $token = $this->tokens->issue($member, $remember);
        $this->members->recordLogin($member->id);
        return ['user' => $member->profile(), 'token' => $token];
    }

    public function reset(string $target, string $id, string $code, string $password, string $binding, string $ip): void
    {
        $this->limiter->check('verify', 'password_reset:' . $target, $ip);
        if (strlen($password) > 72) {
            throw new BusinessException('密码最多72字节', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['password' => ['密码最多72字节']]]);
        }
        $method = str_contains($target, '@') ? 'email' : 'mobile';
        $member = $this->challenges->consume($id, 'password_reset', $method, $target,
            $this->digest('password_reset:' . $method . ':' . $target), $this->digest($binding), $this->digest($id . ':' . $code),
            password_hash($password, PASSWORD_DEFAULT));
        if ($member === null) throw $this->invalidCode();
    }

    private function digest(string $value): string
    {
        if (strlen($this->secret) < 32) throw new BusinessException('认证安全服务不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        return hash_hmac('sha256', $value, $this->secret);
    }

    private function invalidCode(): BusinessException
    {
        return new BusinessException('验证码错误、已使用或已过期，请重新获取', ApiErrorCode::VALIDATION_FAILED, 422,
            ['errors' => ['code' => ['验证码错误、已使用或已过期，请重新获取']]]);
    }
}
