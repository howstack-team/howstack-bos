<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\plugin\application\{ManagePluginsService, PluginLifecycleService};
use app\modules\plugin\contract\PluginUsageInspectorInterface;
use app\modules\plugin\infrastructure\{DatabasePluginInstallationRepository, LocalPluginPackageRegistry, ScopedPluginHttpClient};
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\plugin\dto\{Configuration, ConnectionContext, HttpRequest};
use app\shared\plugin\exception\PluginException;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 生命周期、真实事务、隔离与网络范围；HTTP 不访问真实供应商。 */
final class BusinessPluginLifecycleTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testLifecycleSeparatesApprovalEnableAndRetainsAppendOnlyHistory(): void
    {
        [$service, $repo, $package] = $this->fixture();
        self::assertSame('not_installed', $service->list()[0]['state']);
        $row = $service->transition('magic_cloud', 'install', 0, 1, ['client_ip' => '127.0.0.1', 'client_ip_location' => '本地']);
        self::assertSame('installed', $row['state']);
        $row = $service->transition('magic_cloud', 'enable', $row['revision']);
        $repo->lockAccepting('magic_cloud');
        $row = $service->transition('magic_cloud', 'drain', $row['revision']);
        self::assertSame('draining', $row['state']);
        try {
            $repo->lockAccepting('magic_cloud');
            self::fail('停止接单不能新建连接');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        $row = $service->transition('magic_cloud', 'disable', $row['revision']);
        $row = $service->transition('magic_cloud', 'uninstall', $row['revision']);
        self::assertSame('uninstalled', $row['state']);
        self::assertSame(5, Db::table('business_plugin_events')->where('plugin_id', 'magic_cloud')->count());
        self::assertSame('本地', Db::table('business_plugin_events')->where('plugin_id', 'magic_cloud')->orderBy('id')->value('client_ip_location'));
        self::assertSame($package['package_hash'], $repo->all()['magic_cloud']['package_hash']);
        self::assertNull(Db::table('business_plugin_events')->where('plugin_id', 'magic_cloud')->where('action', 'enable')->value('client_ip'));
        self::assertArrayNotHasKey('package_hash', $service->list()[0]);
        self::assertArrayNotHasKey('entry', $service->list()[0]);
    }

    public function testStaleRevisionCannotWriteAnotherEvent(): void
    {
        [$service] = $this->fixture();
        $service->transition('magic_cloud', 'install', 0);
        try {
            $service->transition('magic_cloud', 'enable', 0);
            self::fail('乐观锁应阻止重复操作');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        self::assertSame(1, Db::table('business_plugin_events')->where('plugin_id', 'magic_cloud')->count());
        self::assertSame('installed', Db::table('business_plugin_installations')->where('plugin_id', 'magic_cloud')->value('state'));
    }

    public function testActiveConnectionsPreventDisableAndUpgradeAndAnyReferencePreventsUninstall(): void
    {
        $inspector = $this->createStub(PluginUsageInspectorInterface::class);
        $inspector->method('inspect')->willReturn(['references' => 2, 'active_references' => 1]);
        [$service, $repo, $package] = $this->fixture([$inspector]);
        $row = $service->transition('magic_cloud', 'install', 0);
        $row = $service->transition('magic_cloud', 'enable', $row['revision']);
        $row = $service->transition('magic_cloud', 'drain', $row['revision']);
        foreach (['disable', 'upgrade', 'uninstall'] as $action) {
            try {
                $repo->transition($package, $action, $row['revision'], null);
                self::fail('有引用时应阻止破坏性操作');
            } catch (BusinessException $error) {
                self::assertSame(409, $error->httpStatus());
            }
        }
        self::assertSame(3, Db::table('business_plugin_events')->where('plugin_id', 'magic_cloud')->count());
    }

    public function testPackageChangeCannotBeApprovedByDisableOrEnable(): void
    {
        [$service, $repo, $package] = $this->fixture();
        $row = $service->transition('magic_cloud', 'install', 0);
        $changed = array_replace($package, ['package_hash' => str_repeat('a', 64), 'version' => '1.2.0']);
        $row = $repo->transition($changed, 'disable', $row['revision'], null);
        self::assertSame($package['package_hash'], $row['package_hash']);
        try {
            $repo->transition($changed, 'enable', $row['revision'], null);
            self::fail('不能隐式批准新包');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        $row = $repo->transition($changed, 'upgrade', $row['revision'], null);
        self::assertSame($changed['package_hash'], $row['package_hash']);
        self::assertSame('installed', $row['state']);
    }

    public function testEventFailureRollsBackInstallation(): void
    {
        [$service] = $this->fixture();
        Db::statement('DROP TABLE orm_business_plugin_events');
        try {
            $service->transition('magic_cloud', 'install', 0);
            self::fail('缺少审计应失败');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame(0, Db::table('business_plugin_installations')->where('plugin_id', 'magic_cloud')->count());
        }
    }

    public function testPageReadPermissionDoesNotGrantInstall(): void
    {
        [$lifecycle] = $this->fixture();
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => ['admin.plugins.view']]]);
        $service = new ManagePluginsService(new AdminAccessControlService($access), $lifecycle);
        $actor = new AdminAccount(1, 'viewer', '查看用户', 'hidden', 1, null);
        $ids = array_column($service->list($actor), 'id');
        sort($ids);
        foreach (['alipay_identity', 'alipay_order_code', 'magic_cloud', 'smtp'] as $id) {
            self::assertContains($id, $ids);
        }
        try {
            $service->transition($actor, 'magic_cloud', 'install', 0, ['client_ip' => '127.0.0.1', 'client_ip_location' => '本地']);
            self::fail('应拒绝安装');
        } catch (BusinessException $error) {
            self::assertSame(403, $error->httpStatus());
        }
        self::assertSame(0, Db::table('business_plugin_installations')->where('plugin_id', 'magic_cloud')->count());
    }

    public function testFixedHttpScopeRejectsWrongPluginAndEndpointWithoutSendingRequest(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push(Middleware::history($history));
        $client = new ScopedPluginHttpClient(new Client(['handler' => $stack]), 'magic_cloud', ['base_url_field' => 'api_url', 'endpoints' => [['method' => 'GET', 'path' => '/v1/areas']]]);
        foreach ([['other', '/v1/areas'], ['magic_cloud', '/v1/create'], ['magic_cloud', '//other.test']] as [$id, $path]) {
            try {
                $client->send(new ConnectionContext($id, new Configuration(['api_url' => 'https://supplier.example.test'])), new HttpRequest('GET', $path));
                self::fail('未授权端点不能发送');
            } catch (PluginException $error) {
                self::assertSame('capability_denied', $error->reason);
            }
        }
        self::assertSame([], $history);
    }

    public function testFixedHttpScopeKeepsTlsAndRejectsLargeResponse(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], str_repeat('x', 2097153))]));
        $stack->push(Middleware::history($history));
        $client = new ScopedPluginHttpClient(new Client(['handler' => $stack]), 'magic_cloud', ['base_url_field' => 'api_url', 'endpoints' => [['method' => 'GET', 'path' => '/v1/areas']]]);
        try {
            $client->send(new ConnectionContext('magic_cloud', new Configuration(['api_url' => 'https://supplier.example.test'])), new HttpRequest('GET', '/v1/areas'));
            self::fail('不能读取超大报文');
        } catch (PluginException $error) {
            self::assertSame('invalid_response', $error->reason);
        }
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function testMySqlDrainSerializesWithConcurrentConnectionCreation(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('真实锁竞争只在独立 MySQL 测试库验证');
        }
        [$service, $repo] = $this->fixture();
        $row = $service->transition('magic_cloud', 'install', 0);
        $row = $service->transition('magic_cloud', 'enable', $row['revision']);
        Db::connection()->beginTransaction();
        $repo->lockAccepting('magic_cloud');
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/fixtures/plugin_lifecycle_race.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            fwrite($pipes[0], json_encode(Db::connection()->getConfig(), JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            self::assertSame("ready\n", fgets($pipes[1]));
            usleep(200000);
            self::assertTrue(proc_get_status($process)['running']);
            $service->transition('magic_cloud', 'drain', $row['revision']);
            Db::connection()->commit();
            $result = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(409, $result['status']);
            self::assertSame(0, Db::table('provider_connections')->count());
        } finally {
            if (Db::connection()->transactionLevel() > 0) {
                Db::connection()->rollBack();
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /** @param list<PluginUsageInspectorInterface> $inspectors 业务引用 @return array<int, mixed> 测试依赖 */
    private function fixture(array $inspectors = []): array
    {
        $packages = new LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        self::assertSame([], $packages->failures());
        $repo = new DatabasePluginInstallationRepository(new DatabaseTransactionManager(), $inspectors);
        return [new PluginLifecycleService($packages, $repo), $repo, $packages->packages()['magic_cloud']];
    }
}
