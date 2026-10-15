<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\exception\BusinessException;
use app\modules\member\contract\MemberTokenServiceInterface;
use app\modules\member\contract\MemberSessionRepositoryInterface;
use app\modules\member\domain\MemberAccount;
use app\shared\http\ApiErrorCode;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/** 会员令牌与后台令牌使用不同受众及用途声明。 */
final readonly class FirebaseMemberTokenService implements MemberTokenServiceInterface
{
    public function __construct(
        private string $secret,
        private string $issuer,
        private string $audience,
        private int $ttl,
        private MemberSessionRepositoryInterface $sessions,
        private int $rememberTtl = 2592000,
    ) {
    }

    public function issue(MemberAccount $account, bool $remember = false): string
    {
        $this->assertConfigured();
        $now = time();
        $id = bin2hex(random_bytes(32));
        $expires = $now + ($remember ? $this->rememberTtl : $this->ttl);
        $version = hash('sha256', $account->passwordHash);
        $this->sessions->create($id, $account->id, $version, $expires);

        return JWT::encode([
            'iss' => $this->issuer, 'aud' => $this->audience, 'sub' => $account->publicId, 'sv' => 2,
            'iat' => $now, 'nbf' => $now, 'exp' => $expires,
            'jti' => $id, 'typ' => 'member_access',
            'pv' => $version,
        ], $this->secret, 'HS256');
    }

    public function parse(string $token): string
    {
        $this->assertConfigured();
        try {
            $payload = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable) {
            throw self::unauthorized();
        }
        $subject = $payload->sub ?? null;
        $memberId = is_string($subject) && \app\modules\member\domain\PublicMemberId::valid($subject) ? $subject : false;
        $id = $payload->jti ?? null;
        if ($memberId === false || ($payload->iss ?? null) !== $this->issuer
            || ($payload->aud ?? null) !== $this->audience
            || ($payload->typ ?? null) !== 'member_access'
            || !is_string($payload->pv ?? null) || !is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)
            || !$this->sessions->active($id, $memberId, $payload->pv)) {
            throw self::unauthorized();
        }

        return $memberId;
    }

    public function revoke(string $token): void
    {
        try {
            $payload = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable) {
            return;
        }
        if (($payload->iss ?? null) === $this->issuer && ($payload->aud ?? null) === $this->audience
            && ($payload->typ ?? null) === 'member_access' && is_string($payload->jti ?? null)) {
            $this->sessions->revoke($payload->jti);
        }
    }

    public function matchesPasswordVersion(string $token, MemberAccount $account): bool
    {
        try {
            $payload = JWT::decode($token, new Key($this->secret, 'HS256'));
            return hash_equals(hash('sha256', $account->passwordHash), (string) ($payload->pv ?? ''));
        } catch (Throwable) {
            return false;
        }
    }

    private function assertConfigured(): void
    {
        if (strlen($this->secret) < 64 || $this->ttl < 300 || $this->rememberTtl < $this->ttl || $this->rememberTtl > 2592000) {
            throw new RuntimeException('会员 JWT 配置缺失或不符合安全要求');
        }
    }

    private static function unauthorized(): BusinessException
    {
        return new BusinessException('会员登录状态已失效', ApiErrorCode::UNAUTHENTICATED, 401);
    }
}
