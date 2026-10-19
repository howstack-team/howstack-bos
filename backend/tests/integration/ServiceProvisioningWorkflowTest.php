<?php

declare(strict_types=1);
namespace tests\integration;

use app\exception\BusinessException;
use app\modules\service_instance\application\{ProvisionPaidOrderService, ViewMemberServicesService};
use app\modules\service_instance\infrastructure\{DatabaseProvisioningRepository, DatabaseMemberServiceRepository};
use app\modules\service_instance\infrastructure\model\{ProvisioningTaskModel as Task, ServiceInstanceModel as Service};
use app\modules\provider\contract\ProviderProvisioningGatewayInterface;
use app\modules\order\application\PayOrderByWalletService;
use app\modules\order\infrastructure\{DatabaseOrderProvisioningRepository, DatabaseOrderPaymentRepository};
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\infrastructure\{DatabaseWalletLedgerRepository, DatabaseOutboxWriter};
use app\modules\product\contract\ProductOrderCapacityInterface;
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use app\shared\plugin\dto\provider\ProvisionResult;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory};

/** 真实迁移和数据库事务，供应商是可控协议替身，不连接生产供应商。 */
final class ServiceProvisioningWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;
    private function fixture(): array
    {
        $member = $this->member(); $order = $this->order($member);
        $snapshot = ['checkout_version' => 1, 'configuration' => ['duration_months' => 1, 'quantity' => 2], 'provisioning' => ['zone' => ['id' => 1], 'quantity' => 2], 'delivery_binding' => ['connection_id' => 1]];
        Db::table('order_items')->where('order_id', $order)->update(['configuration_snapshot' => json_encode($snapshot)]);
        $gateway = new class implements ProviderProvisioningGatewayInterface {
            public array $calls = []; public string $status = 'active'; public bool $timeout = false, $captureFailure = false;
            public function capture(array $binding, array $configuration): array { if ($this->captureFailure) throw new BusinessException('供应不可用', 40900, 409); return ['connection_id' => 1, 'adapter_id' => 'fixture', 'package_hash' => str_repeat('a', 64), 'connection_encrypted' => 'fixture-cipher']; }
            public function execute(array $task, string $password): ProvisionResult {
                TestCase::assertSame(0, Db::connection()->transactionLevel(), '外部调用不能持有业务事务');
                TestCase::assertSame(1, $task['context']['provisioning']['quantity']);
                $this->calls[] = [$task['operation'], $task['request_key'], $password];
                if ($this->timeout) throw new \RuntimeException('timeout secret response must not be persisted');
                return new ProvisionResult($this->status, $this->status === 'failed' || $this->status === 'unknown' ? null : 'remote-' . $task['id'], $this->status === 'active' ? ['192.0.2.10'] : []);
            }
        };
        $tx = new DatabaseTransactionManager();
        $repo = new DatabaseProvisioningRepository(new SodiumSensitiveDataCipher(str_repeat('test-key-', 8)));
        $service = new ProvisionPaidOrderService(new DatabaseOrderProvisioningRepository(), $repo, $gateway, $tx);
        $tx->run(fn () => $service->prepare($order, 'Test@123456'));
        return [$member, $order, $gateway, $repo, $service, $tx];
    }
    private function enqueue(int $order, ProvisionPaidOrderService $service, DatabaseTransactionManager $tx): void
    {
        $tx->run(function () use ($order, $service): void { Db::table('orders')->where('id', $order)->update(['status' => 'paid', 'payment_status' => 'paid', 'paid_minor' => 1200]); $service->enqueue($order); });
    }
    private function due(): void { Task::query()->where('status', 'waiting')->update(['next_attempt_at' => '2000-01-01 00:00:00']); }
    public function testAtomicEnqueueCreatesDistinctUnitsAndCompletesWithImmutableSnapshots(): void
    {
        [$member, $order, $gateway, $repo, $service, $tx] = $this->fixture();
        $this->enqueue($order, $service, $tx); $this->enqueue($order, $service, $tx);
        self::assertSame(2, Task::query()->count()); self::assertSame(2, Service::query()->count());
        self::assertSame(2, Db::table('order_item_units')->whereNotNull('service_id')->count());
        self::assertSame('fulfilling', Db::table('orders')->where('id', $order)->value('status'));
        $raw = Db::table('service_provisioning_credentials')->first(); self::assertStringNotContainsString('Test@123456', $raw->password_encrypted);
        self::assertStringNotContainsString('Test@123456', Task::query()->first()->toJson());
        self::assertSame(2, $service->tick()); self::assertSame(0, $service->tick());
        self::assertSame('completed', Db::table('orders')->where('id', $order)->value('status'));
        self::assertSame(2, Service::query()->where('status', 'active')->whereNotNull('expires_at')->count());
        self::assertCount(2, array_unique(array_column($gateway->calls, 1)));
        $view = new ViewMemberServicesService(new DatabaseMemberServiceRepository(), $repo);
        $id = Service::query()->first()->id;
        self::assertSame('Test@123456', $view->password($member, $id)['password']);
        self::assertArrayNotHasKey('connection_encrypted', $view->detail($member, $id));
        self::assertArrayNotHasKey('password', $view->detail($member, $id));
        try { $view->password($this->member('13800138002'), $id); self::fail('越权应拒绝'); } catch (BusinessException $e) { self::assertSame(404, $e->httpStatus()); }
    }
    public function testTimeoutAndLeaseRecoveryOnlyQueryOriginalRequest(): void
    {
        [, $order, $gateway, $repo, $service, $tx] = $this->fixture(); $this->enqueue($order, $service, $tx);
        $gateway->timeout = true; $service->tick(1); $this->due(); $gateway->timeout = false;
        $service->tick(1);
        self::assertSame(['create', 'query'], array_column($gateway->calls, 0));
        self::assertSame($gateway->calls[0][1], $gateway->calls[1][1]);
        $task = Task::query()->where('status', 'queued')->first();
        $stale = $tx->run(fn () => $repo->claim($task->id));
        Task::query()->whereKey($task->id)->update(['lease_until' => '2000-01-01 00:00:00']);
        $service->tick(1);
        self::assertSame('query', end($gateway->calls)[0]);
        self::assertFalse($tx->run(fn () => $repo->finish($stale, new ProvisionResult('failed'))));
        self::assertSame('succeeded', Task::query()->find($task->id)->status);
    }
    public function testUnknownResultCannotRefundOrRecreateAfterRetryExhausted(): void
    {
        [, $order, $gateway, , $service, $tx] = $this->fixture(); $this->enqueue($order, $service, $tx);
        $gateway->status = 'unknown'; Task::query()->update(['attempts' => 19]); $service->tick();
        self::assertSame(2, Task::query()->where('status', 'attention')->count());
        self::assertSame(2, Service::query()->where('status', 'provisioning')->count());
        $service->retry(Service::query()->first()->id); $service->tick();
        self::assertSame('query', end($gateway->calls)[0]);
        self::assertStringNotContainsString('secret response', Task::query()->first()->toJson());
    }
    public function testConfirmedFailureAllowsOnlyUnrefundedUnitRetryAndPartialCompletionIsVisible(): void
    {
        [, $order, $gateway, , $service, $tx] = $this->fixture(); $this->enqueue($order, $service, $tx);
        $gateway->status = 'active'; $service->tick(1); $gateway->status = 'failed'; $service->tick(1);
        self::assertSame('failed', Db::table('orders')->where('id', $order)->value('status'));
        $task = Task::query()->where('status', 'failed')->first();
        self::assertSame('terminated', Service::query()->find($task->service_id)->status);
        Db::table('order_item_units')->where('id', $task->order_unit_id)->update(['reserved_refund_minor' => 1]);
        try { $service->retry($task->service_id); self::fail('退款占用时不能重试'); } catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
        Db::table('order_item_units')->where('id', $task->order_unit_id)->update(['reserved_refund_minor' => 0]);
        $service->retry($task->service_id); $gateway->status = 'active'; $service->tick();
        self::assertSame('create', end($gateway->calls)[0]);
        self::assertSame('completed', Db::table('orders')->where('id', $order)->value('status'));
    }
    public function testEnqueueFailureRollsBackServicesAndTasks(): void
    {
        [, $order, $gateway, , $service, $tx] = $this->fixture(); $gateway->captureFailure = true;
        try { $this->enqueue($order, $service, $tx); self::fail('供应不可用不能付款'); } catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
        self::assertSame(0, Task::query()->count()); self::assertSame(0, Service::query()->count());
        self::assertSame('unpaid', Db::table('orders')->where('id', $order)->value('payment_status'));
    }
    public function testWalletPaymentAndTaskCreationCommitTogetherAndDuplicatePaymentDoesNotDuplicateTasks(): void
    {
        [$member, $order, $gateway, , $service, $tx] = $this->fixture();
        (new WalletLedgerService(new DatabaseWalletLedgerRepository(), $tx, MessageRuntimeFactory::publisher()))->creditConfirmedRecharge($member, $this->recharge($member));
        $capacity = $this->createStub(ProductOrderCapacityInterface::class);
        $pay = new PayOrderByWalletService(new DatabaseOrderPaymentRepository(), new WalletLedgerService(new DatabaseWalletLedgerRepository(), $tx, MessageRuntimeFactory::publisher()), $tx,
            new DatabaseOutboxWriter(), MessageRuntimeFactory::publisher(), $capacity, $service);
        $gateway->captureFailure = true;
        try { $pay->pay($member, $order); self::fail('任务无法建立应回滚付款'); } catch (BusinessException) {}
        self::assertSame('unpaid', Db::table('orders')->where('id', $order)->value('payment_status'));
        self::assertSame(0, Task::query()->count());
        $gateway->captureFailure = false;
        $pay->pay($member, $order); $pay->pay($member, $order);
        self::assertSame(2, Task::query()->count()); self::assertCount(0, $gateway->calls);
        $service->tick(); self::assertSame('completed', Db::table('orders')->where('id', $order)->value('status'));
    }
    public function testExpiredPasswordNeverLeaksFromStandardServiceResponses(): void
    {
        [$member, $order, , $repo, $service, $tx] = $this->fixture(); $this->enqueue($order, $service, $tx); $service->tick();
        Db::table('service_provisioning_credentials')->where('order_id', $order)->update(['expires_at' => '2000-01-01 00:00:00']); $repo->cleanup();
        self::assertNull(Db::table('service_provisioning_credentials')->where('order_id', $order)->value('password_encrypted'));
        $this->expectException(BusinessException::class); $repo->password($order);
    }
    public function testConcurrentMysqlWorkersDoNotCreateSameUnitTwice(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') self::markTestSkipped('并发领取验证仅使用隔离 MySQL 数据库');
        [, $order, , , $service, $tx] = $this->fixture(); $this->enqueue($order, $service, $tx);
        $trace = tempnam(sys_get_temp_dir(), 'bos-provision-claim-');
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/support/provision_order_worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(['connection' => Db::connection()->getConfig(), 'trace' => $trace], JSON_THROW_ON_ERROR) . "\n");
                self::assertSame("READY\n", fgets($pipes[1]));
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [, $pipes]) { fwrite($pipes[0], "GO\n"); fclose($pipes[0]); }
            foreach ($workers as [$process, $pipes]) {
                stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame('', $errors); self::assertSame(0, proc_close($process));
            }
            $keys = file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertNotEmpty($keys); self::assertSame(count($keys), count(array_unique($keys)));
            self::assertLessThanOrEqual(2, count($keys));
        } finally { unlink($trace); }
    }
    public function testOnlyExplicitResolutionPermissionCanConfirmMissingResourceAndKnownIdentityCannotBeDiscarded(): void
    {
        [, $order, $gateway, , $service, $tx] = $this->fixture(); $this->enqueue($order, $service, $tx);
        $gateway->status = 'unknown'; Task::query()->update(['attempts' => 19]); $service->tick();
        $repository = $this->createStub(\app\modules\admin\access\contract\AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([['permissions' => ['admin.services.cloud-servers.view']]]);
        $access = new \app\modules\admin\access\application\AdminAccessControlService($repository);
        $views = new \app\modules\service_instance\application\ViewServiceInstancesService($access, new \app\modules\service_instance\infrastructure\DatabaseServiceInstanceRepository());
        $manager = new \app\modules\service_instance\application\ManageServiceProvisioningService($access, $views, $service);
        $task = Task::query()->first();
        $viewer = new \app\modules\admin\auth\domain\AdminAccount(1, 'viewer', '只读', '', 1, null);
        foreach (['retry', 'confirmNotCreated'] as $method) {
            try { $method === 'confirmNotCreated' ? $manager->$method($viewer, $task->service_id, '已核查供应商未创建', 'CHECK-TEST') : $manager->$method($viewer, $task->service_id); self::fail('仅查看权限不能操作生产'); } catch (BusinessException $e) { self::assertSame(403, $e->httpStatus()); }
        }
        $founder = new \app\modules\admin\auth\domain\AdminAccount(1, 'founder', '管理员', '', 1, null, true);
        $manager->confirmNotCreated($founder, $task->service_id, '已核查供应商未创建', 'CHECK-TEST-001');
        self::assertSame('failed', $task->fresh()->status);
        self::assertSame('operator_confirmed_not_created', $task->fresh()->error_code);
        self::assertSame('terminated', Service::query()->find($task->service_id)->status);
        $other = Task::query()->where('id', '!=', $task->id)->first(); $other->external_id = 'known-resource'; $other->save();
        try { $manager->confirmNotCreated($founder, $other->service_id, '已核查供应商未创建', 'CHECK-TEST-002'); self::fail('已有供应实例不能确认未创建'); } catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
    }
    public function testMonthEndExpiryDoesNotOverflow(): void
    {
        $schedule = new \app\modules\service_instance\domain\ProvisioningSchedule();
        self::assertSame('2027-02-28 12:34:56', $schedule->expiry(new \DateTimeImmutable('2027-01-31 12:34:56'), 1)->format('Y-m-d H:i:s'));
    }
}
