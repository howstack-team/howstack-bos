<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\service_instance\application\{RenewServiceService, ProcessServiceLifecycleService, ProvisionPaidOrderService};
use app\modules\service_instance\infrastructure\{DatabaseServiceLifecycleRepository, DatabaseProvisioningRepository, DatabaseMemberServiceRepository};
use app\modules\service_instance\infrastructure\model\{ServiceInstanceModel as Service, ProvisioningTaskModel as Provision, LifecycleTaskModel as Task};
use app\modules\order\application\{PayOrderByWalletService, DispatchPaidOrderService};
use app\modules\order\infrastructure\{DatabaseRenewalOrderRepository, DatabaseOrderProvisioningRepository, DatabaseOrderPaymentRepository};
use app\modules\product\contract\{ProductRenewalPricingInterface, ProductOrderCapacityInterface};
use app\modules\provider\contract\{ProviderLifecycleGatewayInterface, ProviderProvisioningGatewayInterface};
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\infrastructure\{DatabaseWalletLedgerRepository, DatabaseOutboxWriter};
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use app\shared\plugin\dto\provider\LifecycleResult;
use app\shared\plugin\exception\PluginException;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory};

/** 真实迁移、订单、钱包、通知和事务；仅供应协议与价格端口使用可控替身。 */
final class ServiceRenewalLifecycleTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function fixture(?string $expiry = null): array
    {
        $member = $this->member();
        $origin = $this->order($member);
        $unit = Db::table('order_item_units')->orderBy('id')->value('id');
        $service = Service::query()->create(['instance_no' => 'bos-renew-fixture', 'product_type' => 'cloud_server', 'member_id' => $member,
            'display_name' => '续费测试实例', 'status' => 'active', 'origin_order_unit_id' => $unit,
            'activated_at' => '2026-01-01 12:00:00', 'expires_at' => $expiry ?? date('Y-m-d H:i:s', time() + 86400 * 30)]);
        Db::table('order_item_units')->where('id', $unit)->update(['service_id' => $service->id]);
        Provision::query()->create(['order_id' => $origin, 'order_unit_id' => $unit, 'service_id' => $service->id, 'request_key' => $service->instance_no,
            'status' => 'succeeded', 'next_attempt_at' => date('Y-m-d H:i:s'), 'external_id' => 'remote-1', 'adapter_id' => 'fixture',
            'context' => ['offer_id' => 1, 'configuration' => ['zone_id' => 1]], 'package_hash' => str_repeat('a', 64), 'connection_encrypted' => 'secret-test-cipher']);
        $tx = new DatabaseTransactionManager();
        $repo = new DatabaseServiceLifecycleRepository();
        $gateway = new class implements ProviderLifecycleGatewayInterface {
            public array $calls = [];
            public bool $unknown = false, $supported = true;
            public function assertSupported(array $source, string $action = 'renew'): void
            {
                if (!$this->supported) throw new PluginException('unsupported', 'lifecycle_unsupported');
            }
            public function execute(array $task): LifecycleResult
            {
                TestCase::assertSame(0, Db::connection()->transactionLevel(), '供应调用禁止持有数据库事务');
                TestCase::assertSame('remote-1', $task['source']['external_id']);
                TestCase::assertSame('secret-test-cipher', $task['source']['connection_encrypted']);
                $this->calls[] = [$task['action'], $task['query_only'], $task['request_key'], $task['target_expires_at']];
                return new LifecycleResult($this->unknown ? 'unknown' : 'succeeded', $task['action'] === 'renew' ? $task['target_expires_at'] : null);
            }
        };
        $pricing = new class implements ProductRenewalPricingInterface {
            public int $amount = 1200;
            public function quote(string $type, array $origin, int $months): array
            {
                return ['durations' => [['months' => 1, 'label' => '月付'], ['months' => 12, 'label' => '年付']],
                    'sale' => ['offer_id' => 1, 'version_id' => 2, 'product_type' => $type, 'type_version' => '2.0.0', 'product_name' => '云服务器'],
                    'price' => ['currency' => 'CNY', 'configuration' => ['zone_id' => 1, 'duration_months' => $months],
                        'amount_minor' => $this->amount, 'subtotal_minor' => 1500, 'discount_minor' => 1500 - $this->amount]];
            }
        };
        $orders = new DatabaseOrderProvisioningRepository();
        $messages = MessageRuntimeFactory::publisher();
        $lifecycle = new ProcessServiceLifecycleService($repo, $orders, $gateway, $tx, $messages);
        $renewals = new RenewServiceService($repo, new DatabaseRenewalOrderRepository(new \app\modules\order\infrastructure\DatabaseOrderManagementRepository()), $pricing, $gateway, $tx, new DatabaseOutboxWriter());
        $wallet = new WalletLedgerService(new DatabaseWalletLedgerRepository(), $tx, $messages);
        $wallet->creditConfirmedRecharge($member, $this->recharge($member, 10000));
        $purchase = new ProvisionPaidOrderService($orders, new DatabaseProvisioningRepository(new SodiumSensitiveDataCipher(str_repeat('key', 32))),
            $this->createStub(ProviderProvisioningGatewayInterface::class), $tx);
        $capacity = $this->createMock(ProductOrderCapacityInterface::class);
        $capacity->expects(self::never())->method('occupy');
        $pay = new PayOrderByWalletService(new DatabaseOrderPaymentRepository(), $wallet, $tx, new DatabaseOutboxWriter(), $messages,
            $capacity, new DispatchPaidOrderService($orders, $purchase, $lifecycle));
        return [$member, $service->id, $repo, $gateway, $pricing, $renewals, $pay, $lifecycle, $tx];
    }

    private function createOrder(int $member, int $service, RenewServiceService $renewals, string $key = 'renewal-fixture-1234'): int
    {
        return $renewals->create($member, $service, ['months' => 1, 'expected_amount_minor' => 1200, 'request_key' => $key], 'member:' . $member)['id'];
    }

    public function testPaymentExtendsOriginalOnceWithoutProvisioningOrNewCapacity(): void
    {
        [$member, $id, , $gateway, , $renewals, $pay, $worker] = $this->fixture('2026-12-31 10:20:30');
        $order = $this->createOrder($member, $id, $renewals);
        self::assertSame($order, $this->createOrder($member, $id, $renewals));
        self::assertSame('renewal', Db::table('orders')->where('id', $order)->value('order_type'));
        self::assertSame(8800, $pay->pay($member, $order));
        self::assertSame(8800, $pay->pay($member, $order));
        self::assertSame('2026-12-31 10:20:30', Service::query()->find($id)->getRawOriginal('expires_at'));
        self::assertSame(1, $worker->tick());
        self::assertSame('2027-01-31 10:20:30', Service::query()->find($id)->getRawOriginal('expires_at'));
        self::assertSame(1, Service::query()->count());
        self::assertSame(1, Provision::query()->count());
        self::assertSame('completed', Db::table('orders')->where('id', $order)->value('status'));
        self::assertCount(1, $gateway->calls);
        self::assertSame(0, $worker->tick());
        $view = (new DatabaseMemberServiceRepository())->find($member, $id);
        self::assertTrue($view['can_renew']);
        self::assertArrayNotHasKey('source', $view);
        self::assertArrayNotHasKey('lease_token', $view);
    }

    public function testExpirySuspendAndLateRenewalResumeAreIdempotent(): void
    {
        [$member, $id, , $gateway, , $renewals, $pay, $worker] = $this->fixture('2026-01-31 12:00:00');
        self::assertSame(1, $worker->tick());
        self::assertSame('expired', Service::query()->find($id)->status);
        self::assertSame(0, $worker->tick());
        $order = $this->createOrder($member, $id, $renewals);
        $pay->pay($member, $order);
        $target = Task::query()->where('order_id', $order)->value('target_expires_at');
        self::assertGreaterThan(date('Y-m-d H:i:s'), $target);
        $worker->tick();
        self::assertSame('expired', Service::query()->find($id)->status, '恢复未确认前不能展示已开通');
        $worker->tick();
        self::assertSame('active', Service::query()->find($id)->status);
        self::assertSame(['suspend', 'renew', 'resume'], array_column($gateway->calls, 0));
        self::assertSame(0, $worker->tick());
    }

    public function testTimeoutLeaseRecoveryOnlyQueriesSameRequestAndRejectsStaleWriter(): void
    {
        [$member, $id, $repo, $gateway, , $renewals, $pay, $worker, $tx] = $this->fixture();
        $order = $this->createOrder($member, $id, $renewals);
        $pay->pay($member, $order);
        $task = $tx->run(fn () => $repo->claim(Task::query()->where('order_id', $order)->value('id')));
        self::assertFalse($task['query_only']);
        $tx->run(fn () => $repo->submitted($task));
        Task::query()->whereKey($task['id'])->update(['lease_until' => '2000-01-01 00:00:00']);
        $gateway->unknown = true;
        $worker->tick();
        self::assertTrue($gateway->calls[0][1]);
        self::assertSame($task['request_key'], $gateway->calls[0][2]);
        self::assertFalse($tx->run(fn () => $repo->finish($task, new LifecycleResult('succeeded', $task['target_expires_at']), null)));
        Task::query()->whereKey($task['id'])->update(['next_attempt_at' => '2000-01-01 00:00:00']);
        $gateway->unknown = false;
        $worker->tick();
        self::assertSame([true, true], array_column($gateway->calls, 1));
        self::assertSame('succeeded', Task::query()->find($task['id'])->status);
    }

    public function testUnknownSuspensionBlocksConcurrentRenewalUntilItIsConfirmed(): void
    {
        [$member, $id, , $gateway, , $renewals, $pay, $worker] = $this->fixture('2026-01-01 00:00:00');
        $gateway->unknown = true;
        $worker->tick();
        $order = $this->createOrder($member, $id, $renewals);
        $pay->pay($member, $order);
        $worker->tick();
        self::assertSame(['suspend'], array_column($gateway->calls, 0));
        self::assertGreaterThan(date('Y-m-d H:i:s'), Task::query()->where('action', 'renew')->value('next_attempt_at'), '互斥任务应让出批次，避免阻塞其他实例');
        Task::query()->whereIn('action', ['suspend', 'renew'])->update(['next_attempt_at' => '2000-01-01 00:00:00']);
        $gateway->unknown = false;
        $worker->tick();
        self::assertSame(['suspend', 'suspend', 'renew'], array_column($gateway->calls, 0));
        $worker->tick();
        self::assertSame('active', Service::query()->find($id)->status);
    }

    public function testQueuedExpiryCannotSuspendPaidOrAlreadyRenewedInstance(): void
    {
        [$member, $id, $repo, $gateway, , $renewals, $pay, $worker, $tx] = $this->fixture('2026-01-01 00:00:00');
        $tx->run(fn () => $repo->expire($id));
        $order = $this->createOrder($member, $id, $renewals);
        $pay->pay($member, $order);
        $worker->tick();
        self::assertSame(['renew'], array_column($gateway->calls, 0));
        self::assertSame('active', Service::query()->find($id)->status);
        self::assertSame('cancelled', Task::query()->where('action', 'suspend')->value('status'));
    }

    public function testOwnerDuplicateOrdersPriceChangesAndUnsupportedPaymentAreRejected(): void
    {
        [$member, $id, , $gateway, $pricing, $renewals, $pay] = $this->fixture();
        try { $renewals->quote($this->member('13800138009'), $id, 1); self::fail('不能越权报价'); }
        catch (BusinessException $error) { self::assertSame(404, $error->httpStatus()); }
        $pricing->amount = 1300;
        try { $this->createOrder($member, $id, $renewals); self::fail('费用变化必须重新确认'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        $pricing->amount = 1200;
        $order = $this->createOrder($member, $id, $renewals);
        try { $this->createOrder($member, $id, $renewals, 'second-renew-fixture'); self::fail('不能重复待付订单'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        $gateway->supported = false;
        try { $pay->pay($member, $order); self::fail('无供应能力不能付款'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame('unpaid', Db::table('orders')->where('id', $order)->value('payment_status'));
        self::assertSame(10000, Db::table('wallet_accounts')->where('member_id', $member)->value('available_minor'));
    }

    public function testReminderUsesSeparateExpiryAndDayBucketWithoutRepeatedDispatch(): void
    {
        [, $id, , , , , , $worker] = $this->fixture(date('Y-m-d H:i:s', time() + 6 * 86400));
        $worker->tick();
        $worker->tick();
        self::assertSame(1, Task::query()->where('action', 'notice7')->count());
        $expiry = date('Y-m-d H:i:s', time() + 2 * 86400);
        Service::query()->whereKey($id)->update(['expires_at' => $expiry]);
        $worker->tick();
        self::assertSame(1, Task::query()->where('action', 'notice3')->count());
        self::assertSame(0, Task::query()->where('action', 'notice1')->count());
        self::assertSame(2, Db::table('messages')->where('scene', 'service_due')->count());
    }
    public function testUnpaidCancelledOrderDoesNotBlockReorderAndCannotBePaid(): void
    {
        [$member, $id, , , , $renewals, $pay, , $tx] = $this->fixture();
        $order = $this->createOrder($member, $id, $renewals);
        $cancel = new \app\modules\order\application\CancelMemberOrderService(new \app\modules\order\infrastructure\DatabaseOrderManagementRepository(), $tx, MessageRuntimeFactory::publisher());
        $cancel->cancel($member, $order);
        $cancel->cancel($member, $order);
        self::assertTrue((new DatabaseMemberServiceRepository())->find($member, $id)['can_renew']);
        $second = $this->createOrder($member, $id, $renewals, 'second-cancelled-test');
        self::assertNotSame($order, $second);
        try { $pay->pay($member, $order); self::fail('已取消不能付款'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame(10000, Db::table('wallet_accounts')->where('member_id', $member)->value('available_minor'));
        self::assertSame('active', Service::query()->find($id)->status);
    }

    public function testConcurrentClaimsSerializeBeforeSubmittingAndUnpaidOrderRemainsVisible(): void
    {
        [$member, $id, $repo, , , $renewals, $pay, , $tx] = $this->fixture('2026-01-01 00:00:00');
        $order = $this->createOrder($member, $id, $renewals);
        $tx->run(fn () => $repo->expire($id));
        $view = (new DatabaseMemberServiceRepository())->find($member, $id);
        self::assertSame($order, $view['renewal_order_id']);
        self::assertTrue($view['renewal_payment_pending']);
        self::assertFalse($view['can_renew']);
        $suspendId = Task::query()->where('action', 'suspend')->value('id');
        $claimed = $tx->run(fn () => $repo->claim($suspendId));
        self::assertNull($claimed['submitted_at']);
        $pay->pay($member, $order);
        $renewId = Task::query()->where('order_id', $order)->value('id');
        self::assertNull($tx->run(fn () => $repo->claim($renewId)), '提交意图尚未记录也必须阻止并发动作');
    }

    public function testMysqlWorkersDoNotRenewOrSuspendSameResourceTwice(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') self::markTestSkipped('多进程行锁只在隔离MySQL执行');
        [$member, $id, , , , $renewals, $pay] = $this->fixture();
        $order = $this->createOrder($member, $id, $renewals);
        $pay->pay($member, $order);
        $target = Task::query()->where('order_id', $order)->value('target_expires_at');
        $trace = tempnam(sys_get_temp_dir(), 'bos-lifecycle-claim-');
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/support/service_lifecycle_worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(['connection' => Db::connection()->getConfig(), 'trace' => $trace], JSON_THROW_ON_ERROR) . "\n");
                self::assertSame("READY\n", fgets($pipes[1]));
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [, $pipes]) { fwrite($pipes[0], "GO\n"); fclose($pipes[0]); }
            foreach ($workers as [$process, $pipes]) {
                stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame('', $errors);
                self::assertSame(0, proc_close($process));
            }
            self::assertCount(1, file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            self::assertSame($target, Service::query()->find($id)->getRawOriginal('expires_at'));
        } finally { unlink($trace); }
    }

    public function testTransferredInstanceCannotChargeOriginalMemberAndTerminalStateCannotBeResurrected(): void
    {
        [$member, $id, $repo, , , $renewals, $pay, , $tx] = $this->fixture();
        $order = $this->createOrder($member, $id, $renewals);
        Service::query()->whereKey($id)->update(['member_id' => $this->member('13800138019')]);
        try { $pay->pay($member, $order); self::fail('归属改变后不能从原会员扣款'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame('unpaid', Db::table('orders')->where('id', $order)->value('payment_status'));
        Service::query()->whereKey($id)->update(['member_id' => $member]);
        $pay->pay($member, $order);
        $task = $tx->run(fn () => $repo->claim(Task::query()->where('order_id', $order)->value('id')));
        $tx->run(fn () => $repo->submitted($task));
        $oldExpiry = Service::query()->find($id)->getRawOriginal('expires_at');
        Service::query()->whereKey($id)->update(['status' => 'terminated']);
        self::assertFalse($tx->run(fn () => $repo->finish($task, new LifecycleResult('succeeded', $task['target_expires_at']), null)));
        self::assertSame('terminated', Service::query()->find($id)->status);
        self::assertSame($oldExpiry, Service::query()->find($id)->getRawOriginal('expires_at'));
        self::assertSame('attention', Task::query()->find($task['id'])->status);
    }

}
