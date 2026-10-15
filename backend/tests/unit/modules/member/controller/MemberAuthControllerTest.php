<?php

declare(strict_types=1);

namespace tests\unit\modules\member\controller;

use app\modules\member\application\LoginMemberService;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\contract\MemberAuthRepositoryInterface;
use app\modules\member\contract\MemberTokenServiceInterface;
use app\modules\member\controller\MemberAuthController;
use app\modules\member\domain\MemberAccount;
use app\shared\http\ApiResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use support\Request;
use app\exception\BusinessException;

/** 验证当前登录契约与业务调用一致，旧字段不能替代 account。 */
final class MemberAuthControllerTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function accounts(): iterable
    {
        yield '手机号' => ['13800138003', 'findByMobile', '13800138003', null];
        yield '邮箱' => ['Member@Example.test', 'findByEmail', 'member@example.test', 'member@example.test'];
    }

    #[DataProvider('accounts')]
    public function testAccountLoginReturnsProfileAndProtectedSession(
        string $identifier,
        string $lookup,
        string $normalized,
        ?string $email,
    ): void {
        $account = new MemberAccount(3, $email === null ? $identifier : null, '客户',
            password_hash('secret123', PASSWORD_DEFAULT), 1, '728461935', $email);
        $members = $this->createMock(MemberAuthRepositoryInterface::class);
        $members->expects(self::once())->method($lookup)->with($normalized)->willReturn($account);
        $members->expects(self::never())->method($lookup === 'findByMobile' ? 'findByEmail' : 'findByMobile');
        $members->expects(self::once())->method('recordLogin')->with(3);
        $tokens = $this->createMock(MemberTokenServiceInterface::class);
        $tokens->expects(self::once())->method('issue')->with($account)->willReturn('test-session');

        // 无论是否夹带旧字段，唯一登录标识始终是 account。
        $response = $this->controller($members, $tokens)->login($this->request([
            'account' => $identifier, 'mobile' => '13900139000', 'password' => 'secret123',
        ]));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($account->profile(), $body['data']['user']);
        self::assertSame($response->getHeader('X-Request-Id'), $body['request_id']);
        self::assertArrayNotHasKey('token', $body['data']);
        self::assertSame('no-store', $response->getHeader('Cache-Control'));
        $cookie = implode('; ', $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
    }

    public function testLegacyMobileFieldCannotReplaceAccount(): void
    {
        $members = $this->createMock(MemberAuthRepositoryInterface::class);
        $members->expects(self::never())->method('findByMobile');
        $members->expects(self::never())->method('findByEmail');
        $members->expects(self::never())->method('recordLogin');
        $tokens = $this->createMock(MemberTokenServiceInterface::class);
        $tokens->expects(self::never())->method('issue');

        $this->expectException(BusinessException::class);
        $this->controller($members, $tokens)->login($this->request([
            'mobile' => '13800138003', 'password' => 'secret123',
        ]));
    }

    /** @return iterable<string, array{int}> 超长密码边界 */
    public static function oversizedPasswords(): iterable
    {
        yield '超过上限一个字符' => [73];
        yield '原有错误上限' => [1024];
    }

    /**
     * 超长密码在身份查询与会话签发前拒绝。
     * @param int $length 密码字符数
     * @return void
     */
    #[DataProvider('oversizedPasswords')]
    public function testOversizedPasswordRejectedBeforeAuthentication(int $length): void
    {
        $members = $this->createMock(MemberAuthRepositoryInterface::class);
        $members->expects(self::never())->method('findByMobile');
        $members->expects(self::never())->method('findByEmail');
        $members->expects(self::never())->method('recordLogin');
        $tokens = $this->createMock(MemberTokenServiceInterface::class);
        $tokens->expects(self::never())->method('issue');

        $this->expectException(BusinessException::class);
        $this->controller($members, $tokens)->login($this->request([
            'account' => '13800138003', 'password' => str_repeat('a', $length),
        ]));
    }

    /**
     * 登录长度上限与现有注册上限一致。
     * @return void
     */
    public function testMaximumLengthPasswordCanAuthenticate(): void
    {
        $password = str_repeat('a', 72);
        $account = new MemberAccount(3, '13800138003', '客户',
            password_hash($password, PASSWORD_DEFAULT), 1, '728461935');
        $members = $this->createMock(MemberAuthRepositoryInterface::class);
        $members->expects(self::once())->method('findByMobile')->with('13800138003')->willReturn($account);
        $members->expects(self::once())->method('recordLogin')->with(3);
        $tokens = $this->createMock(MemberTokenServiceInterface::class);
        $tokens->expects(self::once())->method('issue')->with($account)->willReturn('test-session');

        self::assertSame(200, $this->controller($members, $tokens)->login($this->request([
            'account' => '13800138003', 'password' => $password,
        ]))->getStatusCode());
    }

    private function controller(MemberAuthRepositoryInterface $members, MemberTokenServiceInterface $tokens): MemberAuthController
    {
        return new MemberAuthController(new LoginMemberService($members, $tokens),
            new MemberAuthenticationContext(), new ApiResponse(),
            new \app\modules\member\application\MemberCodeAuthenticationService($members,
                $this->createStub(\app\modules\member\contract\MemberAuthChallengeRepositoryInterface::class), $tokens,
                new \app\modules\member\application\VerificationMessageSender(
                    $this->createStub(\app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface::class),
                    new \app\modules\channel\application\ChannelExecutionConfiguration($this->createStub(\app\modules\channel\contract\ChannelRepositoryInterface::class)),
                    $this->createStub(\app\modules\message\contract\MessageTransportInterface::class),
                    new \app\modules\system_settings\application\GetSystemSettingsService($this->createStub(\app\modules\system_settings\contract\SystemSettingsRepositoryInterface::class))),
                $this->createStub(\app\modules\member\contract\RegistrationRateLimiterInterface::class), str_repeat('s', 32)),
            $tokens, $this->createStub(\app\shared\contract\ClientIpResolverInterface::class),
            new \app\modules\member\application\MemberOAuthAuthenticationService(
                $channels = $this->createStub(\app\modules\channel\contract\ChannelRepositoryInterface::class),
                new \app\modules\channel\application\oauth\OAuthChannelService(
                    new \app\modules\channel\application\ChannelExecutionConfiguration($channels),
                    $this->createStub(\app\modules\channel\contract\TransactionAdapterFactoryInterface::class),
                    $this->createStub(\app\modules\channel\contract\oauth\OAuthStateStoreInterface::class)),
                new \app\modules\channel\application\ChannelExecutionConfiguration($channels),
                $this->createStub(\app\modules\member\contract\MemberOAuthRepositoryInterface::class), $members, $tokens));
    }

    /** @param array<string, string> $data 请求参数 */
    private function request(array $data): Request
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        return new Request("POST /api/console/v1/auth/login HTTP/1.1\r\nHost: example.test\r\n"
            . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }
}
