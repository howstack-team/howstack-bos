<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\exception\BusinessException;
use app\modules\member\contract\MemberTokenServiceInterface;
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
    ) {
    }

    public function issue(MemberAccount $account): string
    {
        $this->assertConfigured();
        $now = time();

        return JWT::encode([
            'iss' => $this->issuer, 'aud' => $this->audience, 'sub' => (string) $account->id,
            'iat' => $now, 'nbf' => $now, 'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(16)), 'typ' => 'member_access',
            'pv' => hash('sha256', $account->passwordHash),
        ], $this->secret, 'HS256');
    }

    public function parse(string $token): int
    {
        $this->assertConfigured();
        try {
            $payload = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable) {
            throw self::unauthorized();
        }
        $memberId = filter_var($payload->sub ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($memberId === false || ($payload->iss ?? null) !== $this->issuer
            || ($payload->aud ?? null) !== $this->audience
            || ($payload->typ ?? null) !== 'member_access'
            || !is_string($payload->pv ?? null)) {
            throw self::unauthorized();
        }

        return $memberId;
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
        if (strlen($this->secret) < 64 || $this->ttl < 300) {
            throw new RuntimeException('会员 JWT 配置缺失或不符合安全要求');
        }
    }

    private static function unauthorized(): BusinessException
    {
        return new BusinessException('会员登录状态已失效', ApiErrorCode::UNAUTHENTICATED, 401);
    }
}
