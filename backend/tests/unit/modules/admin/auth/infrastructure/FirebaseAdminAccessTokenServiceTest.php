<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\auth\infrastructure;

use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\modules\admin\auth\infrastructure\FirebaseAdminAccessTokenService;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 验证管理员 JWT 的签发与校验边界。
 */
final class FirebaseAdminAccessTokenServiceTest extends TestCase
{
    /**
     * 验证签发的 JWT 可以还原管理员标识和有效期。
     * @return void
     */
    public function testIssuedTokenCanBeParsed(): void
    {
        $service = $this->service();
        $issued = $service->issue($this->account());
        $claims = $service->parse($issued->value);

        self::assertSame(42, $claims->adminId);
        self::assertSame($issued->expiresAt, $claims->expiresAt);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $claims->tokenId);
    }

    /**
     * 验证签名被篡改的 JWT 会被拒绝。
     * @return void
     */
    public function testTamperedTokenIsRejected(): void
    {
        $service = $this->service();
        $issued = $service->issue($this->account());
        $segments = explode('.', $issued->value);
        $segments[2] = ($segments[2][0] === 'a' ? 'b' : 'a') . substr($segments[2], 1);

        $this->expectException(AdminAuthenticationException::class);
        $service->parse(implode('.', $segments));
    }

    /** 不接受升级前缺少凭证绑定的会话，以免改密后旧令牌继续有效。 */
    public function testLegacyTokenWithoutCredentialProofIsRejected(): void
    {
        $token = \Firebase\JWT\JWT::encode([
            'iss' => 'https://example.com', 'aud' => 'hlyq-admin', 'sub' => '42',
            'iat' => time(), 'exp' => time() + 7200,
            'jti' => str_repeat('a', 32), 'typ' => 'admin_access',
        ], str_repeat('a', 96), 'HS256');
        $this->expectException(AdminAuthenticationException::class);
        $this->service()->parse($token);
    }

    /**
     * 验证未配置足够长度的密钥时拒绝签发令牌。
     * @return void
     */
    public function testShortSecretIsRejected(): void
    {
        $service = new FirebaseAdminAccessTokenService('short-secret', 'issuer', 'audience', 7200);

        $this->expectException(RuntimeException::class);
        $service->issue($this->account());
    }

    /**
     * 验证安全设置实时影响新令牌，且不改变已经签发的令牌有效期。
     * @return void
     */
    public function testUpdatedSecuritySettingsOnlyAffectNewTokens(): void
    {
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('values')->with('security')->willReturnOnConsecutiveCalls(
            ['security.session_expire' => '30', 'security.login_attempts' => '10', 'security.login_window' => '60'],
            ['security.session_expire' => '15', 'security.login_attempts' => '10', 'security.login_window' => '60'],
        );
        $service = new FirebaseAdminAccessTokenService(
            str_repeat('a', 96),
            'https://example.com',
            'hlyq-admin',
            7200,
            new GetSystemSettingsService($repository),
        );

        $oldToken = $service->issue($this->account());
        $newToken = $service->issue($this->account());
        $oldClaims = $service->parse($oldToken->value);
        $newClaims = $service->parse($newToken->value);

        self::assertSame(1800, $oldClaims->expiresAt - $oldClaims->issuedAt);
        self::assertSame(900, $newClaims->expiresAt - $newClaims->issuedAt);
        self::assertSame($oldToken->expiresAt, $oldClaims->expiresAt);
    }

    /**
     * 创建测试 JWT 服务。
     * @return FirebaseAdminAccessTokenService 测试 JWT 服务
     */
    private function service(): FirebaseAdminAccessTokenService
    {
        return new FirebaseAdminAccessTokenService(str_repeat('a', 96), 'https://example.com', 'hlyq-admin', 7200);
    }

    /**
     * 创建测试管理员账号。
     * @return AdminAccount 测试管理员账号
     */
    private function account(): AdminAccount
    {
        return new AdminAccount(42, 'admin_user', '超级管理员', 'unused-hash', 1, null);
    }
}
