<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\member\application\{MemberCodeAuthenticationService, VerificationMessageSender, LoginMemberService};
use app\modules\member\contract\RegistrationRateLimiterInterface;
use app\modules\member\infrastructure\{DatabaseMemberAuthRepository, DatabaseMemberAuthChallengeRepository, DatabaseMemberSessionRepository, FirebaseMemberTokenService};
use app\modules\member\infrastructure\model\{MemberModel, MemberAuthChallengeModel, MemberSessionModel};
use app\modules\message\contract\MessageTransportInterface;
use app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\infrastructure\DatabaseSystemSettingsRepository;
use app\shared\infrastructure\{SodiumSensitiveDataCipher, DatabaseTransactionManager};
use PHPUnit\Framework\TestCase;
use tests\support\IsolatedBusinessDatabase;

/** 真迁移、ORM事务及JWT；外部短信邮件和限流由受控替身隔离。 */
final class MemberAuthenticationWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function fixture(): array
    {
        $id = $this->member('13800138001');
        MemberModel::query()->whereKey($id)->update(['email' => 'member@example.test']);
        $reader = new GetSystemSettingsService(new DatabaseSystemSettingsRepository());
        $configs = new DatabaseMessageConfigurationRepository(new SodiumSensitiveDataCipher(str_repeat('fixture-key', 6)), new DatabaseTransactionManager());
        foreach (['login_verification', 'password_reset'] as $scene) {
            $type = MessageTypeCatalog::get('customer', $scene);
            $values = [];
            foreach ($type['channels'] as $channel) $values[$channel] = array_replace(MessageTypeCatalog::defaults($type, $channel), ['enabled' => true, 'channel_id' => 17, 'channel_revision' => 2]);
            $configs->save('customer', $scene, 0, $values);
        }
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('find')->willReturn(['revision' => 2, 'configuration_current' => true, 'platform_available' => true, 'status' => 1, 'plugin_id' => 'fixture']);
        $channels->method('configuration')->willReturn([]);
        $transport = new class implements MessageTransportInterface {
            public array $calls = [];
            public function send(string $channel, string $requestKey, array $payload): array
            {
                $this->calls[] = compact('channel', 'requestKey', 'payload');
                return ['status' => 'accepted', 'error' => '', 'retry' => false];
            }
            public function code(): string
            {
                preg_match('/验证码：([0-9]{6})/', $this->calls[array_key_last($this->calls)]['payload']['body'], $match);
                return $match[1];
            }
        };
        $tokens = new FirebaseMemberTokenService(str_repeat('s', 64), 'test', 'member', 7200, new DatabaseMemberSessionRepository());
        $members = new DatabaseMemberAuthRepository();
        $service = new MemberCodeAuthenticationService($members, new DatabaseMemberAuthChallengeRepository(), $tokens,
            new VerificationMessageSender($configs, new ChannelExecutionConfiguration($channels), $transport, $reader),
            $this->createStub(RegistrationRateLimiterInterface::class), str_repeat('k', 32));
        return [$id, $service, $tokens, $transport, $members];
    }

    private function rejected(int $status, callable $call): void
    {
        try { $call(); self::fail('请求应被拒绝'); }
        catch (BusinessException $exception) { self::assertSame($status, $exception->httpStatus()); }
    }

    public function testSmsChallengeBindingsSingleUseAndLogoutRevocation(): void
    {
        [, $service, $tokens, $transport] = $this->fixture();
        $challenge = $service->send('login', '13800138001', 'browser-a', '127.0.0.1');
        $code = $transport->code();
        self::assertSame('sms', $transport->calls[0]['channel']);
        $this->rejected(422, fn () => $service->login('13800138001', $challenge['challenge_id'], $code, 'browser-b', '127.0.0.1', false));
        $this->rejected(422, fn () => $service->login('13800138002', $challenge['challenge_id'], $code, 'browser-a', '127.0.0.1', false));
        $this->rejected(422, fn () => $service->reset('13800138001', $challenge['challenge_id'], $code, 'new-password', 'browser-a', '127.0.0.1'));
        $result = $service->login('13800138001', $challenge['challenge_id'], $code, 'browser-a', '127.0.0.1', true);
        self::assertSame($result['user']['id'], $tokens->parse($result['token']));
        $this->rejected(422, fn () => $service->login('13800138001', $challenge['challenge_id'], $code, 'browser-a', '127.0.0.1', false));
        $tokens->revoke($result['token']);
        $this->rejected(401, fn () => $tokens->parse($result['token']));
    }

    public function testEmailResetRevokesAllSessionsAndOldChallenges(): void
    {
        [$id, $service, $tokens, $transport, $members] = $this->fixture();
        $login = new LoginMemberService($members, $tokens);
        $first = $login->login('13800138001', 'test-password');
        $second = $login->login('member@example.test', 'test-password', true);
        $stale = $service->send('login', '13800138001', 'browser-a', '127.0.0.1');
        $staleCode = $transport->code();
        $reset = $service->send('password_reset', 'member@example.test', 'browser-a', '127.0.0.1');
        $code = $transport->code();
        self::assertSame('email', $transport->calls[1]['channel']);
        $service->reset('member@example.test', $reset['challenge_id'], $code, 'new-password', 'browser-a', '127.0.0.1');
        self::assertTrue(password_verify('new-password', MemberModel::query()->find($id)->password_hash));
        self::assertSame(0, MemberSessionModel::query()->count());
        foreach ([$first, $second] as $session) $this->rejected(401, fn () => $tokens->parse($session['token']));
        $this->rejected(422, fn () => $service->login('13800138001', $stale['challenge_id'], $staleCode, 'browser-a', '127.0.0.1', false));
        $this->rejected(422, fn () => $service->reset('member@example.test', $reset['challenge_id'], $code, 'another-password', 'browser-a', '127.0.0.1'));
        self::assertArrayHasKey('user', $login->login('member@example.test', 'new-password'));
    }

    public function testWrongAttemptsCommitAndExpiryCannotBeBypassed(): void
    {
        [, $service, , $transport] = $this->fixture();
        $challenge = $service->send('login', '13800138001', 'browser-a', '127.0.0.1');
        $code = $transport->code();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) $this->rejected(422, fn () => $service->login('13800138001', $challenge['challenge_id'], $wrong, 'browser-a', '127.0.0.1', false));
        self::assertSame(5, MemberAuthChallengeModel::query()->find($challenge['challenge_id'])->attempts);
        self::assertSame('failed', MemberAuthChallengeModel::query()->find($challenge['challenge_id'])->status);
        $this->rejected(422, fn () => $service->login('13800138001', $challenge['challenge_id'], $code, 'browser-a', '127.0.0.1', false));
        $expired = $service->send('login', '13800138001', 'browser-a', '127.0.0.1');
        MemberAuthChallengeModel::query()->whereKey($expired['challenge_id'])->update(['expires_at' => '2000-01-01 00:00:00']);
        $this->rejected(422, fn () => $service->login('13800138001', $expired['challenge_id'], $transport->code(), 'browser-a', '127.0.0.1', false));
    }

    public function testUnknownAndDisabledAccountsCannotAuthenticateOrRegister(): void
    {
        [$id, $service, , $transport] = $this->fixture();
        $unknown = $service->send('login', '13800138002', 'browser-a', '127.0.0.1');
        $this->rejected(422, fn () => $service->login('13800138002', $unknown['challenge_id'], $transport->code(), 'browser-a', '127.0.0.1', false));
        MemberModel::query()->whereKey($id)->update(['status' => 0]);
        $disabled = $service->send('login', '13800138001', 'browser-a', '127.0.0.1');
        self::assertSame(array_keys($unknown), array_keys($disabled));
        self::assertSame($unknown['expires_in'], $disabled['expires_in']);
        self::assertSame(2, count($transport->calls));
        $this->rejected(422, fn () => $service->login('13800138001', $disabled['challenge_id'], $transport->code(), 'browser-a', '127.0.0.1', false));
        self::assertSame(1, MemberModel::query()->count());
        self::assertSame(0, MemberSessionModel::query()->count());
        $this->rejected(422, fn () => $service->send('login', 'member@example.test', 'browser-a', '127.0.0.1'));
    }

    public function testRememberLifetimeAndServerExpiryAreExplicit(): void
    {
        [, $service, $tokens, , $members] = $this->fixture();
        self::assertSame(['sms_login' => true, 'password_reset' => ['mobile' => true, 'email' => true], 'oauth_providers' => []], $service->options());
        $member = $members->findByMobile('13800138001');
        $ordinary = $tokens->issue($member);
        $remembered = $tokens->issue($member, true);
        $decode = fn ($value) => \Firebase\JWT\JWT::decode($value, new \Firebase\JWT\Key(str_repeat('s', 64), 'HS256'));
        self::assertSame(7200, $decode($ordinary)->exp - $decode($ordinary)->iat);
        self::assertSame(2592000, $decode($remembered)->exp - $decode($remembered)->iat);
        MemberSessionModel::query()->update(['expires_at' => '2000-01-01 00:00:00']);
        $this->rejected(401, fn () => $tokens->parse($ordinary));
        self::assertSame(0, \support\Db::table('messages')->count());
    }
}
