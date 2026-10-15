<?php

declare(strict_types=1);

namespace app\modules\admin\auth\infrastructure;

use app\modules\admin\auth\contract\AdminAccessTokenServiceInterface;
use app\modules\admin\auth\domain\AccessTokenClaims;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\domain\IssuedAccessToken;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\modules\system_settings\application\GetSystemSettingsService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LogicException;
use Random\RandomException;
use RuntimeException;
use UnexpectedValueException;

/**
 * 使用 firebase/php-jwt 签发并验证管理员访问令牌。
 */
final readonly class FirebaseAdminAccessTokenService implements AdminAccessTokenServiceInterface
{
    private const string ALGORITHM = 'HS256';
    private const string TOKEN_TYPE = 'admin_access';

    /**
     * 初始化管理员 JWT 服务。
     * @param string                        $secret   JWT 签名密钥
     * @param string                        $issuer   令牌签发者
     * @param string                        $audience 令牌受众
     * @param int                           $ttl      未注入系统设置时的令牌有效秒数
     * @param GetSystemSettingsService|null $settings 实时安全设置门面
     */
    public function __construct(
        private string $secret,
        private string $issuer,
        private string $audience,
        private int $ttl,
        private ?GetSystemSettingsService $settings = null,
    ) {
    }

    /**
     * 为管理员签发访问令牌。
     * @param AdminAccount $account 管理员账号
     * @return IssuedAccessToken 已签发访问令牌
     * @throws RuntimeException JWT 密钥配置无效时抛出
     * @throws RandomException 安全随机令牌标识无法生成时抛出
     */
    public function issue(AdminAccount $account): IssuedAccessToken
    {
        $this->assertConfigured();
        $issuedAt = time();
        // 修改有效期只影响新签发的令牌；已登录令牌继续按自身 exp 校验。
        $ttl = $this->settings === null ? $this->ttl : $this->settings->security()['session_expire'] * 60;
        $expiresAt = $issuedAt + $ttl;
        $tokenId = bin2hex(random_bytes(16));
        $token = JWT::encode([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => (string) $account->id,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => $tokenId,
            'typ' => self::TOKEN_TYPE,
            'credential_proof' => $this->credentialProof($account),
        ], $this->secret, self::ALGORITHM);

        return new IssuedAccessToken($token, $expiresAt);
    }

    /**
     * 验证并解析访问令牌。
     * @param string $token JWT 原始值
     * @return AccessTokenClaims 已验证令牌声明
     * @throws AdminAuthenticationException 令牌无效、过期或用途不匹配时抛出
     * @throws RuntimeException JWT 密钥配置无效时抛出
     */
    public function parse(string $token): AccessTokenClaims
    {
        $this->assertConfigured();

        try {
            $payload = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (UnexpectedValueException) {
            throw AdminAuthenticationException::invalidToken();
        } catch (LogicException $exception) {
            throw new RuntimeException('JWT 服务配置无效', 0, $exception);
        }

        $adminId = filter_var($payload->sub ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $issuedAt = filter_var($payload->iat ?? null, FILTER_VALIDATE_INT);
        $expiresAt = filter_var($payload->exp ?? null, FILTER_VALIDATE_INT);
        $tokenId = is_string($payload->jti ?? null) ? $payload->jti : '';
        $proof = is_string($payload->credential_proof ?? null) ? $payload->credential_proof : '';

        if (($payload->iss ?? null) !== $this->issuer
            || ($payload->aud ?? null) !== $this->audience
            || ($payload->typ ?? null) !== self::TOKEN_TYPE
            || $adminId === false
            || $issuedAt === false
            || $expiresAt === false
            || !preg_match('/^[a-f0-9]{32}$/', $tokenId)
            || !preg_match('/^[a-f0-9]{64}$/', $proof)) {
            throw AdminAuthenticationException::invalidToken();
        }

        return new AccessTokenClaims($adminId, $tokenId, $issuedAt, $expiresAt, $proof);
    }

    public function matchesAccount(AccessTokenClaims $claims, AdminAccount $account): bool
    {
        $this->assertConfigured();

        return $claims->adminId === $account->id
            && hash_equals($this->credentialProof($account), $claims->credentialProof);
    }

    /** 不在令牌中暴露密码哈希；签名密钥派生的证明随密码哈希变更。 */
    private function credentialProof(AdminAccount $account): string
    {
        return hash_hmac('sha256', 'admin-credential:' . $account->id . ':' . $account->passwordHash . ':' . $account->authVersion, $this->secret);
    }

    /**
     * 断言 JWT 密钥和有效期配置满足安全要求。
     * @return void
     * @throws RuntimeException JWT 配置无效时抛出
     */
    private function assertConfigured(): void
    {
        if (strlen($this->secret) < 64 || $this->ttl < 300) {
            throw new RuntimeException('JWT 认证配置缺失或不符合安全要求');
        }
    }
}
