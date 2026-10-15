<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\member\application\MemberOAuthAuthenticationService;
use app\modules\member\infrastructure\{DatabaseMemberOAuthRepository, DatabaseMemberAuthRepository, DatabaseMemberSessionRepository, FirebaseMemberTokenService};
use app\modules\member\infrastructure\model\{MemberModel, MemberOAuthPendingModel, MemberOAuthIdentityModel, MemberSessionModel};
use app\modules\channel\application\{ChannelExecutionConfiguration, oauth\OAuthChannelService};
use app\modules\channel\contract\{ChannelRepositoryInterface, TransactionAdapterFactoryInterface, oauth\OAuthStateStoreInterface};
use app\shared\plugin\contract\OAuthAdapterInterface;
use app\shared\plugin\dto\{Configuration, oauth\OAuthIdentity};
use PHPUnit\Framework\TestCase;
use tests\support\IsolatedBusinessDatabase;

/** 真实前向迁移、ORM唯一约束及JWT会话；平台HTTP由独立协议测试覆盖。 */
final class MemberOAuthAuthenticationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function fixture(): array
    {
        $id = $this->member();
        $runtime = (object) ['enabled' => true, 'revision' => 2, 'appId' => '12345678', 'subject' => 'OPENID', 'exchangeCalls' => 0];
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $row = static fn () => ['id' => 17, 'plugin_id' => 'qq_login', 'revision' => $runtime->revision,
            'configuration_current' => true, 'platform_available' => true, 'status' => $runtime->enabled ? 1 : 0];
        $channels->method('list')->willReturnCallback(static fn () => ['data' => $runtime->enabled ? [$row()] : [], 'total' => $runtime->enabled ? 1 : 0]);
        $channels->method('find')->willReturnCallback($row);
        $channels->method('configuration')->willReturnCallback(static fn () => ['app_id' => $runtime->appId]);
        $states = new class implements OAuthStateStoreInterface {
            private array $values = [];
            public function issue(array $attempt, string $browserBinding): string {
                $state = bin2hex(random_bytes(32)); $this->values[$state] = [$attempt, $browserBinding]; return $state;
            }
            public function consume(string $state, string $browserBinding): ?array {
                $value = $this->values[$state] ?? null;
                if ($value === null || !hash_equals($value[1], $browserBinding)) return null;
                unset($this->values[$state]); return $value[0];
            }
        };
        $adapter = new class($runtime) implements OAuthAdapterInterface {
            public function __construct(private object $runtime) {}
            public function validateConfiguration(Configuration $configuration): Configuration { return $configuration; }
            public function authorize(string $state, Configuration $configuration): string { return 'https://graph.qq.com/oauth2.0/authorize?state=' . $state; }
            public function authenticate(string $code, Configuration $configuration): OAuthIdentity {
                $this->runtime->exchangeCalls++;
                return new OAuthIdentity('qq_login', $configuration->values['app_id'], $this->runtime->subject, 'UNION', '相同昵称');
            }
        };
        $adapters = $this->createStub(TransactionAdapterFactoryInterface::class);
        $adapters->method('oauth')->willReturn($adapter);
        $config = new ChannelExecutionConfiguration($channels);
        $tokens = new FirebaseMemberTokenService(str_repeat('k', 64), 'fixture', 'member', 7200, new DatabaseMemberSessionRepository());
        $members = new DatabaseMemberAuthRepository();
        $repo = new DatabaseMemberOAuthRepository();
        $service = new MemberOAuthAuthenticationService($channels, new OAuthChannelService($config, $adapters, $states), $config, $repo, $members, $tokens);
        return [$id, $service, $tokens, $runtime, $repo, $members];
    }

    private function authorize(MemberOAuthAuthenticationService $service, string $binding = 'browser-a', bool $remember = true): array
    {
        $url = $service->start('qq', $remember, $binding, true);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('mobile', $query['display']);
        return [$query['state'], $service->complete($query['state'], 'CODE', $binding)];
    }

    private function rejected(int $status, callable $call): void
    {
        try { $call(); self::fail('请求必须被拒绝'); }
        catch (BusinessException $error) { self::assertSame($status, $error->httpStatus()); }
    }

    public function testFirstAuthorizationBindsThenSubsequentLoginIssuesRevocableRememberedSession(): void
    {
        [, $service, $tokens, $runtime] = $this->fixture();
        self::assertSame(['qq'], $service->providers());
        [$state, $pending] = $this->authorize($service);
        self::assertSame('binding_required', $pending['status']);
        self::assertSame(0, MemberSessionModel::query()->count());
        self::assertArrayNotHasKey('user', $pending);
        $this->rejected(409, fn () => $service->complete($state, 'CODE', 'browser-a'));
        self::assertSame(1, $runtime->exchangeCalls);
        $this->rejected(409, fn () => $service->pending($pending['ticket'], 'browser-b'));
        self::assertSame('qq', $service->pending($pending['ticket'], 'browser-a')['provider']);
        $result = $service->bind($pending['ticket'], 'browser-a', '13800138001', 'test-password');
        self::assertSame($result['user']['id'], $tokens->parse($result['token']));
        self::assertTrue($result['remember_me']);
        self::assertSame(1, MemberOAuthIdentityModel::query()->count());
        self::assertSame(0, MemberOAuthPendingModel::query()->count());
        $this->rejected(409, fn () => $service->bind($pending['ticket'], 'browser-a', '13800138001', 'test-password'));
        [, $second] = $this->authorize($service, 'browser-a', false);
        self::assertSame('authenticated', $second['status']);
        self::assertFalse($second['remember_me']);
        $tokens->revoke($second['token']);
        $this->rejected(401, fn () => $tokens->parse($second['token']));
    }

    public function testWrongBrowserDoesNotConsumeStateOrCallPlatform(): void
    {
        [, $service, , $runtime] = $this->fixture();
        $url = $service->start('qq', false, 'browser-a', false);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->rejected(409, fn () => $service->complete($query['state'], 'CODE', 'browser-b'));
        self::assertSame(0, $runtime->exchangeCalls);
        self::assertSame('binding_required', $service->complete($query['state'], 'CODE', 'browser-a')['status']);
    }

    public function testFiveWrongPasswordsCommitAndPreventCorrectPasswordAfterwards(): void
    {
        [, $service] = $this->fixture();
        [, $pending] = $this->authorize($service);
        for ($i = 0; $i < 5; $i++) $this->rejected(422, fn () => $service->bind($pending['ticket'], 'browser-a', '13800138001', 'wrong-password'));
        self::assertSame(5, MemberOAuthPendingModel::query()->first()->attempts);
        $this->rejected(409, fn () => $service->bind($pending['ticket'], 'browser-a', '13800138001', 'test-password'));
        self::assertSame(0, MemberOAuthIdentityModel::query()->count());
        self::assertSame(0, MemberSessionModel::query()->count());
    }

    public function testExpiredDisabledAndRevisedChannelsCannotBind(): void
    {
        [, $service, , $runtime] = $this->fixture();
        [, $pending] = $this->authorize($service);
        $runtime->revision = 3;
        $this->rejected(409, fn () => $service->bind($pending['ticket'], 'browser-a', '13800138001', 'test-password'));
        $runtime->revision = 2; $runtime->enabled = false;
        self::assertSame([], $service->providers());
        $this->rejected(409, fn () => $service->bind($pending['ticket'], 'browser-a', '13800138001', 'test-password'));
        $runtime->enabled = true;
        MemberOAuthPendingModel::query()->update(['expires_at' => '2000-01-01 00:00:00']);
        $this->rejected(409, fn () => $service->pending($pending['ticket'], 'browser-a'));
    }

    public function testSameIdentityCannotLinkAnotherMemberAndAppScopeIsIndependent(): void
    {
        [, $service, , $runtime] = $this->fixture();
        $this->member('13800138002');
        [, $first] = $this->authorize($service, 'browser-a');
        [, $second] = $this->authorize($service, 'browser-b');
        $service->bind($first['ticket'], 'browser-a', '13800138001', 'test-password');
        $this->rejected(409, fn () => $service->bind($second['ticket'], 'browser-b', '13800138002', 'test-password'));
        self::assertSame(1, MemberOAuthIdentityModel::query()->count());
        $runtime->appId = '87654321';
        [, $third] = $this->authorize($service, 'browser-c');
        self::assertSame('binding_required', $third['status']);
        $service->bind($third['ticket'], 'browser-c', '13800138002', 'test-password');
        self::assertSame(2, MemberOAuthIdentityModel::query()->count());
    }

    public function testCaseSensitiveSubjectsAndMemberAppUniqueness(): void
    {
        [, $service, , $runtime] = $this->fixture();
        $this->member('13800138002');
        [, $first] = $this->authorize($service, 'browser-a');
        $service->bind($first['ticket'], 'browser-a', '13800138001', 'test-password');
        $runtime->subject = 'openid';
        [, $second] = $this->authorize($service, 'browser-b');
        self::assertSame('binding_required', $second['status']);
        $this->rejected(409, fn () => $service->bind($second['ticket'], 'browser-b', '13800138001', 'test-password'));
        $service->bind($second['ticket'], 'browser-b', '13800138002', 'test-password');
        self::assertSame(2, MemberOAuthIdentityModel::query()->count());
    }

    public function testCurrentPasswordVersionAndDisabledMemberAreChecked(): void
    {
        [$id, $service, , , $repo, $members] = $this->fixture();
        [, $pending] = $this->authorize($service);
        $old = $members->findById($id);
        MemberModel::query()->whereKey($id)->update(['password_hash' => password_hash('new-password', PASSWORD_DEFAULT)]);
        self::assertNull($repo->bind(hash('sha256', $pending['ticket']), hash('sha256', 'browser-a'), $old));
        $service->bind($pending['ticket'], 'browser-a', '13800138001', 'new-password');
        MemberModel::query()->whereKey($id)->update(['status' => 0]);
        $this->rejected(403, fn () => $this->authorize($service));
    }
}
