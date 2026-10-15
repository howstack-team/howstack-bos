<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\member\application\{RegisterMemberService, SendRegistrationCodeService, LoginMemberService};
use app\modules\member\contract\RegistrationRateLimiterInterface;
use app\modules\member\infrastructure\{DatabaseMemberRegistrationRepository, DatabaseMemberAuthRepository, DatabaseMemberRepository, FirebaseMemberTokenService};
use app\modules\member\validation\MemberRegistrationValidator;
use app\modules\message\contract\MessageTransportInterface;
use app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\order\infrastructure\DatabaseOrderMemberReference;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\infrastructure\DatabaseSystemSettingsRepository;
use app\shared\infrastructure\{SodiumSensitiveDataCipher, DatabaseTransactionManager};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真迁移及持久化；外部短信、邮件和 Redis 仅通过契约替身隔离。 */
final class MemberRegistrationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function fixture(string $status = 'accepted'): array
    {
        $settings = new DatabaseSystemSettingsRepository();
        $reader = new GetSystemSettingsService($settings);
        $members = new DatabaseMemberRegistrationRepository();
        $configs = new DatabaseMessageConfigurationRepository(new SodiumSensitiveDataCipher(str_repeat('registration-fixture-key', 3)), new DatabaseTransactionManager());
        $transport = new class ($status) implements MessageTransportInterface {
            public array $calls = [];
            public function __construct(private string $status) {}
            public function send(string $channel, string $requestKey, array $payload): array
            {
                $this->calls[] = ['channel' => $channel, 'key' => $requestKey, 'payload' => $payload];
                return ['status' => $this->status, 'error' => '', 'retry' => false];
            }
        };
        $limiter = new class implements RegistrationRateLimiterInterface {
            public array $calls = [];
            public function check(string $operation, string $target, string $ip): void { $this->calls[] = [$operation, $target, $ip]; }
        };
        $codes = new SendRegistrationCodeService($reader, $members, $configs, $transport, $limiter, str_repeat('secret', 12));
        $registration = new RegisterMemberService($reader, $members, $codes, $limiter);
        $type = MessageTypeCatalog::get('customer', 'register_verification');
        $channels = [];
        foreach ($type['channels'] as $channel) $channels[$channel] = array_replace(MessageTypeCatalog::defaults($type, $channel), ['enabled' => true, 'channel_id' => 17, 'channel_revision' => 2]);
        $configs->save('customer', 'register_verification', 0, $channels);
        return [$registration, $codes, $settings, $transport, $reader];
    }

    private function input(string $method, string $account, array $extra = []): array
    {
        return MemberRegistrationValidator::input($extra + ['method' => $method, 'account' => $account, 'password' => 'new-password', 'status' => 0, 'public_id' => '10000000'], true);
    }

    private function rejected(int $status, callable $call): void
    {
        try { $call(); self::fail('应拒绝请求'); }
        catch (BusinessException $error) { self::assertSame($status, $error->httpStatus()); }
    }

    public function testMalformedInputsAndUnavailableTemplateCannotBypassVerification(): void
    {
        foreach ([['method' => [], 'account' => '13800138000'], ['method' => 'mobile', 'account' => []], ['method' => 'email', 'account' => 'not-email'], ['method' => 'mobile', 'account' => '12345']] as $bad) {
            $this->rejected(422, fn () => MemberRegistrationValidator::input($bad + ['password' => 'password'], true));
        }
        [$register, $codes, , $transport] = $this->fixture();
        Db::table('message_configurations')->delete();
        $this->rejected(503, fn () => $codes->send('mobile', '13800138000', 'a', '127.0.0.1'));
        self::assertSame([], $transport->calls);
        self::assertSame(0, Db::table('member_registration_challenges')->count());
        $this->rejected(422, fn () => $register->register($this->input('mobile', '13800138000'), 'a', '127.0.0.1'));
        self::assertSame(0, Db::table('members')->count());
    }

    public function testPhoneVerificationProducesPublicIdAndDoesNotUseOrdinaryMessageLogs(): void
    {
        [$register, $codes, , $transport] = $this->fixture();
        $result = $codes->send('mobile', '13800138003', 'browser-a', '127.0.0.1');
        self::assertSame(['challenge_id', 'expires_in', 'retry_after'], array_keys($result));
        self::assertSame('sms', $transport->calls[0]['channel']);
        preg_match('/验证码：([0-9]{6})/', $transport->calls[0]['payload']['body'], $match);
        $input = $this->input('mobile', '13800138003', ['code' => $match[1], 'challenge_id' => $result['challenge_id']]);
        $profile = $register->register($input, 'browser-a', '127.0.0.1');
        self::assertMatchesRegularExpression('/^[1-9][0-9]{8}$/D', $profile['id']);
        self::assertSame(['id', 'mobile', 'email', 'nickname'], array_keys($profile));
        $row = Db::table('members')->first();
        self::assertSame($profile['id'], $row->public_id);
        self::assertSame(1, (int) $row->status);
        self::assertNotNull($row->mobile_verified_at);
        self::assertTrue(password_verify('new-password', $row->password_hash));
        self::assertSame($codes->digest($result['challenge_id'] . ':' . $match[1]), Db::table('member_registration_challenges')->value('code_hash'));
        self::assertSame('used', Db::table('member_registration_challenges')->value('status'));
        self::assertSame(0, Db::table('messages')->count());
        self::assertSame(0, Db::table('message_deliveries')->count());
        $this->rejected(422, fn () => $codes->verify($result['challenge_id'], 'mobile', '13800138003', 'browser-a', $match[1]));
        $this->rejected(409, fn () => $register->register($input, 'browser-a', '127.0.0.1'));
    }

    public function testEmailRegistrationAndEmailPasswordLoginKeepInternalOwnershipId(): void
    {
        [$register, $codes, $settings, $transport] = $this->fixture();
        $settings->save('registration', ['registration.email_enabled' => '1']);
        $input = $this->input('email', ' User@Example.COM ');
        self::assertSame('user@example.com', $input['account']);
        $sent = $codes->send('email', $input['account'], 'browser-a', '127.0.0.1');
        self::assertSame('email', $transport->calls[0]['channel']);
        preg_match('/验证码：([0-9]{6})/', $transport->calls[0]['payload']['body'], $match);
        $input['code'] = $match[1]; $input['challenge_id'] = $sent['challenge_id'];
        $profile = $register->register($input, 'browser-a', '127.0.0.1');
        self::assertNull($profile['mobile']);
        self::assertSame('user@example.com', $profile['email']);
        $row = Db::table('members')->where('email', 'user@example.com')->first();
        self::assertNotNull($row->email_verified_at);
        $repo = new DatabaseMemberAuthRepository();
        $tokens = new FirebaseMemberTokenService(str_repeat('s', 64), 'test', 'member', 3600, new \app\modules\member\infrastructure\DatabaseMemberSessionRepository());
        $result = (new LoginMemberService($repo, $tokens))->login('USER@example.COM', 'new-password');
        self::assertSame($profile, $result['user']);
        self::assertSame($profile['id'], $tokens->parse($result['token']));
        self::assertSame((int) $row->id, $repo->findByPublicId($profile['id'])->id);
        $this->rejected(409, fn () => $register->register($this->input('email', 'USER@example.com'), 'browser-a', '127.0.0.1'));
    }

    public function testUnverifiedMethodsAndRuntimePolicyChanges(): void
    {
        [$register, $codes, $settings, , $reader] = $this->fixture();
        $this->rejected(403, fn () => $register->register($this->input('email', 'a@example.com'), 'a', '127.0.0.1'));
        $settings->save('registration', ['registration.mobile_verification' => '0', 'registration.email_enabled' => '1', 'registration.email_verification' => '0']);
        foreach ([['mobile', '13800138002'], ['email', 'b@example.com']] as [$method, $account]) {
            $profile = $register->register($this->input($method, $account), 'a', '127.0.0.1');
            $row = Db::table('members')->where('public_id', $profile['id'])->first();
            self::assertNull($row->{$method . '_verified_at'});
        }
        $this->rejected(403, fn () => $codes->send('email', 'b@example.com', 'a', '127.0.0.1'));
        $settings->save('registration', ['registration.email_enabled' => '0', 'registration.mobile_enabled' => '0']);
        self::assertSame(0, $reader->registration()['email_enabled']);
        $this->rejected(403, fn () => $register->register($this->input('mobile', '13800138004'), 'a', '127.0.0.1'));
    }

    public function testCodeBindingExpiryWrongAttemptsAndReplacement(): void
    {
        [, $codes, , $transport] = $this->fixture();
        $first = $codes->send('mobile', '13800138009', 'a', '127.0.0.1')['challenge_id'];
        preg_match('/验证码：([0-9]{6})/', $transport->calls[0]['payload']['body'], $match);
        $code = $match[1];
        $this->rejected(422, fn () => $codes->verify($first, 'mobile', '13800138009', 'b', $code));
        $this->rejected(422, fn () => $codes->verify($first, 'mobile', '13800138008', 'a', $code));
        $wrong = $code === '999999' ? '000000' : '999999';
        for ($i = 0; $i < 5; $i++) $this->rejected(422, fn () => $codes->verify($first, 'mobile', '13800138009', 'a', $wrong));
        self::assertSame(5, (int) Db::table('member_registration_challenges')->where('challenge_id', $first)->value('attempts'));
        $this->rejected(422, fn () => $codes->verify($first, 'mobile', '13800138009', 'a', $code));
        $second = $codes->send('mobile', '13800138009', 'a', '127.0.0.1')['challenge_id'];
        $third = $codes->send('mobile', '13800138009', 'a', '127.0.0.1')['challenge_id'];
        self::assertSame('superseded', Db::table('member_registration_challenges')->where('challenge_id', $second)->value('status'));
        preg_match('/验证码：([0-9]{6})/', $transport->calls[2]['payload']['body'], $match);
        Db::table('member_registration_challenges')->where('challenge_id', $third)->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->rejected(422, fn () => $codes->verify($third, 'mobile', '13800138009', 'a', $match[1]));
    }

    public function testUnknownSendCannotBeVerifiedOrRetriedAsNotification(): void
    {
        [, $codes, , $transport] = $this->fixture('unknown');
        $this->rejected(503, fn () => $codes->send('mobile', '13800138006', 'a', '127.0.0.1'));
        self::assertCount(1, $transport->calls);
        self::assertSame('failed', Db::table('member_registration_challenges')->value('status'));
        self::assertSame(0, Db::table('messages')->count());
    }

    public function testAdminCreateAndUpdatePreservePublicIdAndClearContactVerification(): void
    {
        $repo = new DatabaseMemberRepository(new DatabaseOrderMemberReference());
        $id = $repo->save(null, ['nickname' => '管理员新建', 'mobile' => '13800138001', 'email' => null, 'password_hash' => 'hash']);
        $number = $repo->find($id)['public_id'];
        self::assertMatchesRegularExpression('/^[1-9][0-9]{8}$/D', $number);
        Db::table('members')->where('id', $id)->update(['mobile_verified_at' => date('Y-m-d H:i:s')]);
        $repo->save($id, ['mobile' => '13800138002', 'public_id' => '11111111']);
        self::assertSame($number, $repo->find($id)['public_id']);
        self::assertNull(Db::table('members')->where('id', $id)->value('mobile_verified_at'));
    }
}
