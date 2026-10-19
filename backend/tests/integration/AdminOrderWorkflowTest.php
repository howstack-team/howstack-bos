<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\order\application\ManageAdminOrdersService;
use app\modules\order\application\PayOrderByWalletService;
use app\modules\order\infrastructure\DatabaseOrderManagementRepository;
use app\modules\order\infrastructure\DatabaseOrderPaymentRepository;
use app\modules\order\infrastructure\DatabaseOrderFinanceRepository;
use app\modules\order\infrastructure\DatabaseOrderRepository;
use app\modules\product\infrastructure\DatabaseOrderSalesCatalog;
use app\modules\product\infrastructure\ProductTypeRegistry;
use app\modules\product\infrastructure\model\OfferModel;
use app\modules\product\infrastructure\model\OfferVersionModel;
use app\modules\product\infrastructure\model\ProductModel;
use app\modules\compute\domain\ComputeTypeHandler;
use app\modules\compute\domain\ComputeResources;
use app\modules\compute\infrastructure\DatabaseComputeResourceRepository;
use app\modules\compute\infrastructure\DatabaseComputeInventoryRepository;
use app\modules\compute\validation\ComputeResourceValidator;
use app\modules\compute\validation\CloudOfferValidator;
use app\modules\compute\application\OrderComputeCapacityService;
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\application\ManageFinanceService;
use app\modules\finance\infrastructure\DatabaseWalletLedgerRepository;
use app\modules\finance\infrastructure\DatabaseOutboxWriter;
use app\modules\finance\infrastructure\DatabaseFinanceWorkflowRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory};

final class AdminOrderWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;
    use \tests\support\UndeliveredOrderFixture;

    private function actor(bool $founder = true): AdminAccount
    {
        return new AdminAccount(1, 'fixture-admin', '管理员', 'hash', 1, null, $founder);
    }
    private function capacity(): \app\modules\product\infrastructure\ProductPurchaseRegistry
    {
        return \tests\support\ProductRuntimeFactory::purchases();
    }
    private function wallet(): WalletLedgerService
    {
        return new WalletLedgerService(new DatabaseWalletLedgerRepository(), new DatabaseTransactionManager(), MessageRuntimeFactory::publisher());
    }
    private function service(?\app\shared\contract\OutboxWriterInterface $outbox = null): ManageAdminOrdersService
    {
        $tx = new DatabaseTransactionManager(); $publisher = MessageRuntimeFactory::publisher();
        return new ManageAdminOrdersService(new AdminAccessControlService(new DatabaseAdminAccessRepository()), new DatabaseOrderManagementRepository(),
            new DatabaseOrderSalesCatalog(), new \app\modules\product\infrastructure\ProductTypeRegistry([new ComputeTypeHandler()]), $tx,
            new PayOrderByWalletService(new DatabaseOrderPaymentRepository(), $this->wallet(), $tx, new DatabaseOutboxWriter(), $publisher, $this->capacity(), new \tests\support\RecordingOrderProvisioning()),
            new \app\modules\order\application\CreatePurchaseOrderService(new DatabaseOrderManagementRepository(), new DatabaseOrderSalesCatalog(),
                new \app\modules\product\infrastructure\ProductTypeRegistry([new ComputeTypeHandler()]), $tx, $outbox ?? new DatabaseOutboxWriter(), $publisher,
                \tests\support\ProductRuntimeFactory::purchases(), \tests\support\ProductRuntimeFactory::purchases(), new \tests\support\RecordingOrderProvisioning(), \tests\support\ProductRuntimeFactory::purchases()),
            \tests\support\ProductRuntimeFactory::purchases());
    }
    private function fixture(): array
    {
        $repo = new DatabaseComputeResourceRepository(); $validator = new ComputeResourceValidator(); $ids = []; $resources = [];
        foreach (['regions' => [], 'zones' => ['region_id' => 1], 'lines' => ['zone_id' => 1, 'network_configuration' => ['egress' => ['min' => 0, 'default' => 5, 'included' => 5], 'ingress' => ['min' => 0, 'default' => 0], 'ip' => ['min' => 1, 'max' => 4, 'default' => 1, 'included' => 1]]],
            'flavor_groups' => [], 'flavors' => ['zone_id' => 1, 'group_id' => 1, 'cpu' => 2, 'memory_mib' => 2048, 'architecture' => 'x86_64', 'prices' => ['month' => 100]],
            'image_types' => [], 'images' => ['zone_id' => 1, 'type_id' => 1, 'architecture' => 'x86_64', 'min_disk_gib' => 20, 'login_methods' => ['password']],
            'disk_types' => ['zone_id' => 1, 'min_gib' => 20, 'max_gib' => 200, 'step_gib' => 10, 'max_count' => 2, 'prices' => ['month' => 1]]] as $kind => $fields) {
            $row = $repo->save($kind, null, $validator->validate($kind, ['code' => $kind, 'name' => $kind, 'status' => 1] + $fields));
            $ids[$kind] = [$row['id']];
        }
        foreach (ComputeResources::KINDS as $kind) $resources[$kind] = $repo->list($kind);
        $draft = (new CloudOfferValidator())->validate(['name' => '测试配置', 'code' => 'order-fixture', 'draft' => ['schema_version' => '2.0.0', 'resource_ids' => $ids,
            'storage_policy' => ['default_type_id' => 1, 'default_size_gib' => 20], 'durations' => [['months' => 12, 'label' => '年付', 'discount_kind' => 'rate', 'rate_bps' => 8000]]]])['draft'];
        $snapshot = (new ComputeTypeHandler())->snapshot($draft, $resources) + ['product_name' => '云服务器'];
        $offer = OfferModel::query()->create(['product_id' => ProductModel::query()->where('definition_key','cloud_server')->value('id'), 'name' => '测试配置', 'code' => 'order-fixture', 'description' => '', 'draft' => $draft, 'status' => 1, 'revision' => 1]);
        $version = OfferVersionModel::query()->create(['offer_id' => $offer->id, 'version' => 1, 'type_version' => '2.0.0', 'snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', 'fixture'), 'published_by' => 1]);
        $offer->published_version_id = $version->id; $offer->save();
        (new DatabaseComputeInventoryRepository())->configure(1, 1, 2, 0);
        $selection = ['region_id' => 1,'zone_id' => 1,'line_id' => 1,'flavor_id' => 1,'image_id' => 1,'duration_months' => 12,'quantity' => 2,
            'system_disk' => ['type_id' => 1,'size_gib' => 20], 'data_disks' => [],'network' => ['egress_mbps' => 5,'ingress_mbps' => 0,'ipv4_count' => 1]];
        $price = $this->service()->preview($this->actor(), $offer->id, $version->id, $selection, true);
        return ['member_id' => $this->member(), 'offer_id' => $offer->id, 'version_id' => $version->id, 'selection' => $selection,
            'expected_amount_minor' => $price['amount_minor'], 'remark' => '', 'request_key' => bin2hex(random_bytes(16))];
    }
    public function testEmailOnlyMemberCanBeSelectedByPublicIdAndReceiveAnOrder(): void
    {
        $input = $this->fixture();
        Db::table('members')->where('id', $input['member_id'])->update(['mobile' => null, 'email' => 'email-only@example.com']);
        $number = Db::table('members')->where('id', $input['member_id'])->value('public_id');
        $members = (new DatabaseOrderManagementRepository())->members($number);
        self::assertCount(1, $members);
        self::assertStringContainsString($number, $members[0]['label']);
        self::assertCount(1, (new DatabaseOrderManagementRepository())->members('email-only@example.com'));
        $order = $this->service()->create($this->actor(), $input);
        self::assertSame('', Db::table('orders')->where('id', $order['id'])->value('member_mobile_snapshot'));
        self::assertSame($input['member_id'], (int) Db::table('orders')->where('id', $order['id'])->value('member_id'));
    }

    public function testCreateSnapshotsPriceAndAllocatesEachInstanceExactlyOnce(): void
    {
        $input = $this->fixture(); $service = $this->service(); $result = $service->create($this->actor(), $input);
        self::assertSame($result, $service->create($this->actor(), $input));
        self::assertSame(1, Db::table('orders')->count()); self::assertSame(2, Db::table('order_item_units')->count());
        self::assertSame($input['expected_amount_minor'], (int) Db::table('order_item_units')->sum('allocated_minor'));
        self::assertSame(0, (int) Db::table('compute_inventory_pools')->value('occupied'));
        $detail = (new DatabaseOrderRepository())->findWithItems($result['id']);
        self::assertArrayNotHasKey('configuration_snapshot', $detail['items'][0]);
        self::assertSame(2, count($detail['units']));
        $input['selection']['quantity'] = 1;
        $this->expectException(BusinessException::class); $service->create($this->actor(), $input);
    }
    public function testPriceTamperingAndStoppedVersionDoNotLeaveAnOrder(): void
    {
        $input = $this->fixture(); $input['expected_amount_minor']--;
        try { $this->service()->create($this->actor(), $input); self::fail(); } catch (BusinessException) { self::assertSame(0, Db::table('orders')->count()); self::assertSame(0, Db::table('order_requests')->count()); }
        $input['expected_amount_minor']++; OfferModel::query()->whereKey($input['offer_id'])->update(['status' => 0]);
        $this->expectException(BusinessException::class); $this->service()->create($this->actor(), $input);
    }
    public function testPayUsesStockAndFullInstanceRefundReleasesOnlyThatInstance(): void
    {
        $input = $this->fixture(); $id = $this->service()->create($this->actor(), $input)['id'];
        $tx = new DatabaseTransactionManager();
        $finance = new ManageFinanceService(new AdminAccessControlService(new DatabaseAdminAccessRepository()), new DatabaseFinanceWorkflowRepository(new \app\modules\service_instance\infrastructure\DatabaseUndeliveredRefundGuard()), new DatabaseWalletLedgerRepository(), $this->wallet(), new DatabaseOrderFinanceRepository($this->capacity()), $tx, new DatabaseOutboxWriter(), new \app\modules\service_instance\infrastructure\DatabaseUndeliveredRefundGuard());
        $finance->create($this->actor(), 'recharges', ['request_key' => bin2hex(random_bytes(16)), 'member_id' => $input['member_id'], 'amount_minor' => 10000, 'external_reference' => 'ORDER-DEPOSIT']);
        $this->service()->act($this->actor(), $id, 'pay', []); $this->service()->act($this->actor(), $id, 'pay', []);
        self::assertSame(2, (int) Db::table('compute_inventory_pools')->value('occupied'));
        self::assertSame(0, (int) Db::table('compute_inventory_pools')->value('reserved'));
        self::assertSame(10000 - $input['expected_amount_minor'], (int) Db::table('wallet_accounts')->value('available_minor'));
        $this->confirmFixtureUndelivered($id);
        $unit = Db::table('order_item_units')->orderBy('id')->first();
        $refund = $finance->create($this->actor(), 'refunds', ['request_key' => bin2hex(random_bytes(16)), 'member_id' => $input['member_id'], 'source_id' => $unit->id, 'amount_minor' => (int) $unit->allocated_minor, 'reason' => '未开通实例退款']);
        $finance->act($this->reviewer(), 'refunds', $refund['id'], 'approve', ['request_key' => bin2hex(random_bytes(16)), 'review_reason' => '已核实未交付']);
        $finance->act($this->actor(), 'refunds', $refund['id'], 'execute', ['request_key' => bin2hex(random_bytes(16))]);
        self::assertSame(1, (int) Db::table('compute_inventory_pools')->value('occupied'));
        self::assertSame('partial_refund', Db::table('orders')->value('payment_status'));
        self::assertSame(2, Db::table('service_instances')->count(), '失败交付及核查记录必须保留');
    }
    public function testStockFailureRollsBackPaymentAndLedger(): void
    {
        $input = $this->fixture(); $id = $this->service()->create($this->actor(), $input)['id'];
        $recharge = $this->recharge($input['member_id']); $this->wallet()->creditConfirmedRecharge($input['member_id'], $recharge);
        // Ensure sufficient funds, so the failure is inventory rather than funds.
        Db::table('wallet_accounts')->update(['available_minor' => 10000]); Db::table('wallet_funding_lots')->update(['credited_minor' => 10000, 'remaining_minor' => 10000]);
        (new DatabaseComputeInventoryRepository())->configure(1, 1, 1, 1);
        try { $this->service()->act($this->actor(), $id, 'pay', []); self::fail(); } catch (BusinessException $e) { self::assertStringContainsString('库存不足', $e->getMessage()); }
        self::assertSame('unpaid', Db::table('orders')->value('payment_status'));
        self::assertSame(10000, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(0, Db::table('wallet_consumptions')->count());
        self::assertSame(0, Db::table('compute_inventory_reservations')->count());
    }
    public function testCancelAndRemarkAreSafeAndViewOnlyCannotWrite(): void
    {
        $input = $this->fixture(); $id = $this->service()->create($this->actor(), $input)['id'];
        $this->service()->act($this->actor(), $id, 'update', ['remark' => '跟进中', 'expected_remark' => '']);
        try { $this->service()->act($this->actor(), $id, 'update', ['remark' => '覆盖', 'expected_remark' => '']); self::fail(); } catch (BusinessException) { self::assertSame('跟进中', Db::table('orders')->value('remark')); }
        $this->service()->act($this->actor(), $id, 'cancel', []); $this->service()->act($this->actor(), $id, 'cancel', []);
        self::assertSame('cancelled', Db::table('orders')->value('status'));
        try { $this->service()->act($this->actor(), $id, 'pay', []); self::fail(); } catch (BusinessException) { self::assertSame(0, Db::table('payment_transactions')->count()); }
        $this->expectException(BusinessException::class); $this->service()->choices($this->actor(false));
    }
    public function testOutboxFailureRollsBackCreationAndRequestKey(): void
    {
        $input = $this->fixture();
        $outbox = new class implements \app\shared\contract\OutboxWriterInterface { public function append(string $key,string $type,int $aggregateId,array $payload): void { throw new \RuntimeException('fixture rollback'); } };
        try { $this->service($outbox)->create($this->actor(), $input); self::fail(); } catch (\RuntimeException) { self::assertSame(0, Db::table('orders')->count()); self::assertSame(0, Db::table('order_item_units')->count()); self::assertSame(0, Db::table('order_requests')->count()); }
    }
    public function testSelectionValidatorDropsSecretsAndNestedUnknownFields(): void
    {
        $clean = \app\modules\compute\validation\ComputePurchaseValidator::clean(['quantity' => '2','password' => 'must-not-store','network' => ['egress_mbps' => '5','token' => 'secret'],'data_disks' => [['type_id' => '1','size_gib' => '20','password' => 'secret']]]);
        self::assertSame(['quantity' => 2,'network' => ['egress_mbps' => 5],'data_disks' => [['type_id' => 1,'size_gib' => 20]]], $clean);
    }

    public function testControllerWhitelistAndActualViewOnlyRole(): void
    {
        $input = $this->fixture();
        $context = new \app\modules\admin\auth\application\AdminAuthenticationContext();
        $controller = new \app\modules\order\controller\AdminOrderManagementController($this->service(), $context, new \app\shared\http\ApiResponse());
        $payload = json_encode($input + ['status' => 'completed','paid_minor' => 999999], JSON_THROW_ON_ERROR);
        $request = new \support\Request("POST /api/admin/v1/orders HTTP/1.1\r\nHost: localhost\r\nOrigin: http://localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
        $context->set($request, $this->actor());
        $result = json_decode($controller->create($request)->rawBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['code']); self::assertSame('unpaid', Db::table('orders')->value('payment_status'));
        self::assertSame(0, (int) Db::table('orders')->value('paid_minor'));
        $now = date('Y-m-d H:i:s');
        $role = $this->row('admin_roles', ['name' => '订单只读','code' => 'order_viewer','status' => 1,'created_at' => $now,'updated_at' => $now]);
        Db::table('admin_user_roles')->insert(['admin_user_id' => 1,'role_id' => $role]);
        Db::table('admin_role_permissions')->insert(['role_id' => $role,'permission_code' => 'admin.page.order-list.view']);
        $context->set($request, $this->actor(false));
        try { $controller->create($request); self::fail(); } catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        self::assertSame(1, Db::table('orders')->count());
    }
    public function testConcurrentPaymentAndCancellationSerializeOnOrder(): void
    {
        if ($this->mysqlServer === null || !function_exists('pcntl_fork')) self::markTestSkipped('并发订单操作需MySQL和pcntl');
        $input = $this->fixture(); $id = $this->service()->create($this->actor(), $input)['id'];
        $recharge = $this->recharge($input['member_id']); $this->wallet()->creditConfirmedRecharge($input['member_id'], $recharge);
        Db::table('wallet_accounts')->update(['available_minor' => 10000]); Db::table('wallet_funding_lots')->update(['credited_minor' => 10000,'remaining_minor' => 10000]);
        $results = $this->concurrently('order', [['id' => $id,'action' => 'pay'],['id' => $id,'action' => 'cancel']]);
        $codes = array_column($results,'code'); sort($codes); self::assertSame([0,409], $codes);
        $paid = Db::table('orders')->value('payment_status') === 'paid';
        self::assertSame($paid ? 'paid' : 'cancelled', Db::table('orders')->value('status'));
        self::assertSame($paid ? 10000 - $input['expected_amount_minor'] : 10000, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame($paid ? 2 : 0, (int) Db::table('compute_inventory_pools')->value('occupied'));
    }
    /** 两个独立数据库连接同步竞争，不共享父进程连接或事务。 */
    private function concurrently(string $resource, array $inputs): array
    {
        $this->testCapsule->getConnection()->disconnect();
        // fork 前关闭管理连接，防止子进程析构共享 PDO 导致父进程清理连接失效。
        $this->mysqlServer = null;
        $signal = tempnam(sys_get_temp_dir(), 'finance-start-');
        unlink($signal);
        $files = [];
        $children = [];
        foreach ($inputs as $input) {
            $file = tempnam(sys_get_temp_dir(), 'finance-result-');
            $files[] = $file;
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('无法创建并发测试进程');
            }
            if ($pid === 0) {
                while (!file_exists($signal)) {
                    usleep(1000);
                }
                try {
                    Db::statement('SET SESSION innodb_lock_wait_timeout = 5');
                    $result = ['code' => 0] + $this->service()->act($this->actor(), $input['id'], $input['action'], []);
                } catch (BusinessException $error) {
                    $result = ['code' => $error->httpStatus()];
                } catch (\Throwable $error) {
                    $result = ['code' => 500, 'error' => get_class($error)];
                }
                file_put_contents($file, json_encode($result, JSON_THROW_ON_ERROR));
                $this->testCapsule->getConnection()->disconnect();
                exit(0);
            }
            $children[] = $pid;
        }
        touch($signal);
        $results = [];
        try {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            foreach ($files as $file) {
                $results[] = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            $database = require dirname(__DIR__, 2) . '/config/database.php';
            $mysql = $database['connections']['mysql'];
            $this->mysqlServer = new \PDO('mysql:host=' . $mysql['host'] . ';port=' . $mysql['port'], $mysql['username'], $mysql['password']);
            unlink($signal);
            foreach ($files as $file) {
                unlink($file);
            }
        }
        return $results;
    }
}
