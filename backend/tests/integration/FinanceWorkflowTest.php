<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\finance\application\ManageFinanceService;
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\infrastructure\DatabaseFinanceWorkflowRepository;
use app\modules\finance\infrastructure\DatabaseWalletLedgerRepository;
use app\modules\finance\infrastructure\DatabaseOutboxWriter;
use app\modules\order\application\PayOrderByWalletService;
use app\modules\order\infrastructure\DatabaseOrderPaymentRepository;
use app\modules\order\infrastructure\DatabaseOrderFinanceRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
use tests\support\MessageRuntimeFactory;

final class FinanceWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;
    use \tests\support\UndeliveredOrderFixture;

    private function actor(bool $founder = true): AdminAccount
    {
        return new AdminAccount(1, 'fixture-admin', '测试管理员', 'hash', 1, null, $founder);
    }

    private function finance(?\app\shared\contract\OutboxWriterInterface $outbox = null): ManageFinanceService
    {
        $ledger = new DatabaseWalletLedgerRepository();
        $transactions = new DatabaseTransactionManager();
        return new ManageFinanceService(new AdminAccessControlService(new DatabaseAdminAccessRepository()),
            new DatabaseFinanceWorkflowRepository(new \app\modules\service_instance\infrastructure\DatabaseUndeliveredRefundGuard()), $ledger,
            new WalletLedgerService($ledger, $transactions, MessageRuntimeFactory::publisher()),
            new DatabaseOrderFinanceRepository(\tests\support\ProductRuntimeFactory::purchases()), $transactions, $outbox ?? new DatabaseOutboxWriter(), new \app\modules\service_instance\infrastructure\DatabaseUndeliveredRefundGuard());
    }

    private function input(array $values): array
    {
        return ['request_key' => bin2hex(random_bytes(16))] + $values;
    }

    private function deposit(int $memberId): array
    {
        return $this->finance()->create($this->actor(), 'recharges', $this->input([
            'member_id' => $memberId, 'amount_minor' => 1500, 'external_reference' => 'OFFLINE-TEST',
        ]));
    }

    private function paidOrder(int $member): int
    {
        $order = $this->order($member);
        $this->deposit($member);
        $ledger = new DatabaseWalletLedgerRepository();
        $transactions = new DatabaseTransactionManager();
        (new PayOrderByWalletService(new DatabaseOrderPaymentRepository(),
            new WalletLedgerService($ledger, $transactions, MessageRuntimeFactory::publisher()),
            $transactions, new DatabaseOutboxWriter(), MessageRuntimeFactory::publisher(), \tests\support\ProductRuntimeFactory::purchases(), new \tests\support\RecordingOrderProvisioning()))->pay($member, $order);
        $this->confirmFixtureUndelivered($order);
        return $order;
    }

    public function testOfflineRechargeMatchesPaymentAndRejectsChangedReplay(): void
    {
        $member = $this->member();
        $input = $this->input(['member_id' => $member, 'amount_minor' => 1500, 'external_reference' => 'BANK-001']);
        $service = $this->finance();
        $first = $service->create($this->actor(), 'recharges', $input);
        self::assertSame($first, $service->create($this->actor(), 'recharges', $input));
        self::assertSame(1500, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(1, Db::table('wallet_entries')->count());
        self::assertSame(1, Db::table('finance_operations')->count());
        self::assertSame('credited', Db::table('recharges')->value('status'));
        try {
            $service->create($this->actor(), 'recharges', array_replace($input, ['amount_minor' => 1499]));
            self::fail('同键不同内容必须拒绝');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        try {
            $service->create($this->actor(), 'recharges', $this->input(['member_id' => $member, 'amount_minor' => 1500, 'external_reference' => 'BANK-001']));
            self::fail('同一线下收款凭证不得重复入账');
        } catch (BusinessException) {
            self::assertSame(1, Db::table('recharges')->count());
            self::assertSame(1, Db::table('finance_operations')->count());
        }
    }

    public function testPerInstanceRefundRestoresSourcesAndKeepsOtherInstanceUntouched(): void
    {
        $member = $this->member();
        $order = $this->paidOrder($member);
        $units = Db::table('order_item_units')->orderBy('id')->pluck('id');
        $service = $this->finance();
        $refund = $service->create($this->actor(), 'refunds', $this->input([
            'member_id' => $member, 'source_id' => $units[0], 'amount_minor' => 600, 'reason' => '实例退款',
        ]));
        self::assertSame(600, (int) Db::table('order_item_units')->where('id', $units[0])->value('reserved_refund_minor'));
        $service->act($this->reviewer(), 'refunds', $refund['id'], 'approve', $this->input(['review_reason' => '已核对来源及未交付证据']));
        $execute = $this->input([]);
        $service->act($this->actor(), 'refunds', $refund['id'], 'execute', $execute);
        $service->act($this->actor(), 'refunds', $refund['id'], 'execute', $execute);
        self::assertSame(900, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(900, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
        self::assertSame(600, (int) Db::table('wallet_consumptions')->value('restored_minor'));
        self::assertSame(600, (int) Db::table('orders')->where('id', $order)->value('refunded_minor'));
        self::assertSame('partial_refund', Db::table('orders')->where('id', $order)->value('payment_status'));
        self::assertSame(0, (int) Db::table('order_item_units')->where('id', $units[1])->value('refunded_minor'));
        self::assertSame(1, Db::table('wallet_entries')->where('entry_type', 'refund_credit')->count());
        self::assertSame(1, Db::table('finance_outbox')->where('event_type', 'refund.completed')->count());
        $this->expectException(BusinessException::class);
        $service->create($this->actor(), 'refunds', $this->input([
            'member_id' => $member, 'source_id' => $units[0], 'amount_minor' => 101, 'reason' => '超过剩余额度',
        ]));
    }

    public function testInvoiceReservationBlocksRefundAndRedReleasesQuota(): void
    {
        $member = $this->member();
        $order = $this->paidOrder($member);
        $service = $this->finance();
        $invoice = $service->create($this->actor(), 'invoices', $this->input([
            'member_id' => $member, 'source_id' => $order, 'amount_minor' => 1200, 'title' => '测试公司', 'invoice_type' => 'electronic',
        ]));
        self::assertCount(0, $service->options($this->actor(), 'refunds', $member));
        self::assertCount(0, $service->options($this->actor(), 'invoices', $member));
        $service->act($this->actor(), 'invoices', $invoice['id'], 'approve', $this->input(['review_reason' => '已核对来源及未交付证据']));
        $service->act($this->actor(), 'invoices', $invoice['id'], 'issue', $this->input(['external_reference' => 'INVOICE-001']));
        $service->act($this->actor(), 'invoices', $invoice['id'], 'red', $this->input(['external_reference' => 'RED-001']));
        self::assertCount(2, $service->options($this->actor(), 'refunds', $member));
        self::assertSame(1200, $service->options($this->actor(), 'invoices', $member)[0]['available_minor']);
    }

    public function testWithdrawalFreezesSourcesRejectionRestoresAndPayoutRunsOnce(): void
    {
        $member = $this->member();
        $recharge = $this->deposit($member);
        $service = $this->finance();
        $input = ['member_id' => $member, 'source_id' => $recharge['id'], 'amount_minor' => 500, 'reason' => '退还未消费充值'];
        $withdraw = $service->create($this->actor(), 'withdrawals', $this->input($input));
        self::assertSame(1000, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(500, (int) Db::table('wallet_accounts')->value('frozen_minor'));
        self::assertSame(1000, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
        $service->act($this->reviewer(), 'withdrawals', $withdraw['id'], 'reject', $this->input(['review_reason' => '资料不符']));
        self::assertSame(1500, (int) Db::table('wallet_accounts')->value('available_minor'));
        $withdraw = $service->create($this->actor(), 'withdrawals', $this->input($input));
        $service->act($this->reviewer(), 'withdrawals', $withdraw['id'], 'approve', $this->input(['review_reason' => '已核对来源及未交付证据']));
        $payout = $this->input(['external_reference' => 'BANK-PAYOUT-001', 'payout_note' => '已通过银行核对实际打款']);
        $service->act($this->actor(), 'withdrawals', $withdraw['id'], 'payout', $payout);
        $service->act($this->actor(), 'withdrawals', $withdraw['id'], 'payout', $payout);
        self::assertSame(1000, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(0, (int) Db::table('wallet_accounts')->value('frozen_minor'));
        self::assertSame(1, Db::table('wallet_entries')->where('entry_type', 'withdrawal_payout')->count());
    }

    public function testWrongOwnerOverInvoicingAndViewOnlyCannotOperate(): void
    {
        $member = $this->member();
        $other = $this->member('13800138002');
        $order = $this->paidOrder($member);
        foreach ([$other, $member] as $actorMember) {
            try {
                $this->finance()->create($this->actor(), 'invoices', $this->input([
                    'member_id' => $actorMember, 'source_id' => $order, 'amount_minor' => 1201,
                    'title' => '越界发票', 'invoice_type' => 'electronic',
                ]));
                self::fail('越权或超额应拒绝');
            } catch (BusinessException) {
                self::assertSame(0, Db::table('invoice_records')->count());
            }
        }
        $this->expectException(BusinessException::class);
        $this->finance()->create($this->actor(false), 'recharges', $this->input([
            'member_id' => $member, 'amount_minor' => 100, 'external_reference' => 'UNAUTHORIZED',
        ]));
    }

    public function testRejectedRefundReleasesReservationAndInvalidAmountsDoNotWrite(): void
    {
        $member = $this->member();
        $this->paidOrder($member);
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $service = $this->finance();
        $input = ['member_id' => $member, 'source_id' => $unit, 'amount_minor' => 600, 'reason' => '退款测试'];
        $refund = $service->create($this->actor(), 'refunds', $this->input($input));
        $service->act($this->reviewer(), 'refunds', $refund['id'], 'reject', $this->input(['review_reason' => '不符合政策']));
        self::assertSame(0, (int) Db::table('order_item_units')->where('id', $unit)->value('reserved_refund_minor'));
        foreach ([0, -1, 601] as $invalid) {
            try {
                $service->create($this->actor(), 'refunds', $this->input(array_replace($input, ['amount_minor' => $invalid])));
                self::fail('非法金额必须拒绝');
            } catch (BusinessException) {
                self::assertSame(1, Db::table('refund_requests')->count());
            }
        }
        $other = $service->create($this->actor(), 'refunds', $this->input($input));
        self::assertNotSame($refund['id'], $other['id']);
        self::assertSame(600, (int) Db::table('order_item_units')->where('id', $unit)->value('reserved_refund_minor'));
    }

    public function testRefundOutboxFailureRollsBackFundsOrderAndAudit(): void
    {
        $member = $this->member();
        $order = $this->paidOrder($member);
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $refund = $this->finance()->create($this->actor(), 'refunds', $this->input([
            'member_id' => $member, 'source_id' => $unit, 'amount_minor' => 600, 'reason' => '故障测试',
        ]));
        $this->finance()->act($this->reviewer(), 'refunds', $refund['id'], 'approve', $this->input(['review_reason' => '已核对来源及未交付证据']));
        $before = Db::table('finance_operations')->count();
        $outbox = new class implements \app\shared\contract\OutboxWriterInterface {
            public function append(string $key, string $type, int $aggregateId, array $payload): void
            {
                (new DatabaseOutboxWriter())->append($key, $type, $aggregateId, $payload);
                throw new \RuntimeException('退款提交前故障注入');
            }
        };
        try {
            $this->finance($outbox)->act($this->actor(), 'refunds', $refund['id'], 'execute', $this->input(['review_reason' => '已核对来源及未交付证据']));
            self::fail('故障必须传播');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('故障注入', $error->getMessage());
        }
        self::assertSame(300, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(300, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
        self::assertSame(0, (int) Db::table('orders')->where('id', $order)->value('refunded_minor'));
        self::assertSame(600, (int) Db::table('order_item_units')->where('id', $unit)->value('reserved_refund_minor'));
        self::assertSame('approved', Db::table('refund_requests')->where('id', $refund['id'])->value('status'));
        self::assertSame(0, Db::table('finance_outbox')->where('event_type', 'refund.completed')->count());
        self::assertSame($before, Db::table('finance_operations')->count());
        self::assertSame(0, Db::connection()->transactionLevel());
    }

    public function testRealControllerWritesAndCombinesListFiltersAndViewOnlyCannotWrite(): void
    {
        $member = $this->member();
        $context = new \app\modules\admin\auth\application\AdminAuthenticationContext();
        $responses = new \app\shared\http\ApiResponse();
        $controller = new \app\modules\finance\controller\AdminFinanceWorkflowController($this->finance(), $context, $responses, new \app\shared\infrastructure\TrustedProxyClientIpResolver(), new \app\shared\infrastructure\Ip2RegionLocationResolver(new \Ip2Region()));
        $payload = json_encode($this->input(['member_id' => $member, 'amount_minor' => 321, 'external_reference' => 'HTTP-TEST']), JSON_THROW_ON_ERROR);
        $request = new \support\Request("POST /api/admin/v1/finance/recharges HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
        $context->set($request, $this->actor());
        $response = json_decode($controller->create($request, 'recharges')->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $response['code']);
        self::assertSame(321, (int) Db::table('wallet_accounts')->value('available_minor'));
        $access = new AdminAccessControlService(new DatabaseAdminAccessRepository());
        $read = new \app\modules\finance\controller\AdminFinanceRecordController(
            new \app\modules\finance\application\ViewFinanceRecordsService($access, new \app\modules\finance\infrastructure\DatabaseFinanceRecordRepository()), $context, $responses);
        $query = new \support\Request('GET /api/admin/v1/finance/recharges?member_id=' . $member . '&number=RC&sort_by=amount_minor&sort_order=asc HTTP/1.1' . "\r\nHost: localhost\r\n\r\n");
        $context->set($query, $this->actor());
        $records = json_decode($read->index($query, 'recharges')->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $records['data']['total']);
        self::assertSame(321, $records['data']['data'][0]['amount_minor']);
        $now = date('Y-m-d H:i:s');
        $role = $this->row('admin_roles', ['name' => '财务只读', 'code' => 'finance_viewer', 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        Db::table('admin_user_roles')->insert(['admin_user_id' => 1, 'role_id' => $role]);
        Db::table('admin_role_permissions')->insert(['role_id' => $role, 'permission_code' => 'admin.finance.recharges.view']);
        $context->set($query, $this->actor(false));
        self::assertSame(200, $read->index($query, 'recharges')->getStatusCode());
        $context->set($request, $this->actor(false));
        try {
            $controller->create($request, 'recharges');
            self::fail('仅查看权限不能入账');
        } catch (BusinessException $error) {
            self::assertSame(403, $error->httpStatus());
        }
        self::assertSame(1, Db::table('recharges')->count());
    }

    public function testUnallocatedHistoricalInvoiceBlocksNewInvoiceAndRefund(): void
    {
        $member = $this->member();
        $order = $this->paidOrder($member);
        $now = date('Y-m-d H:i:s');
        $invoice = $this->row('invoice_records', ['invoice_no' => 'LEGACY', 'member_id' => $member, 'order_id' => $order,
            'amount_minor' => 1200, 'invoice_type' => 'paper', 'title' => '历史', 'status' => 'issued', 'created_at' => $now, 'updated_at' => $now]);
        self::assertSame([], $this->finance()->options($this->actor(), 'refunds', $member));
        self::assertSame([], $this->finance()->options($this->actor(), 'invoices', $member));
        $this->expectException(BusinessException::class);
        $this->finance()->act($this->actor(), 'invoices', $invoice, 'red', $this->input(['external_reference' => 'LEGACY-RED']));
    }

    public function testMysqlConcurrentRechargeReplayAndWithdrawalsCannotOverdraw(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1' || !function_exists('pcntl_fork')) {
            self::markTestSkipped('并发锁测试需要独立 MySQL 和 pcntl');
        }
        $member = $this->member();
        $input = $this->input(['member_id' => $member, 'amount_minor' => 1500, 'external_reference' => 'CONCURRENT-DEPOSIT']);
        $results = $this->concurrently('recharges', [$input, $input]);
        self::assertSame(0, $results[0]['code']);
        self::assertSame($results[0], $results[1]);
        self::assertSame(1500, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(1, Db::table('wallet_entries')->count());
        $rechargeId = $results[0]['id'];
        $withdraw = ['member_id' => $member, 'source_id' => $rechargeId, 'amount_minor' => 1000, 'reason' => '并发提现测试'];
        $results = $this->concurrently('withdrawals', [$this->input($withdraw), $this->input($withdraw)]);
        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame([0, 409], $codes);
        self::assertSame(1, Db::table('withdrawal_requests')->count());
        self::assertSame(500, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(1000, (int) Db::table('wallet_accounts')->value('frozen_minor'));
        self::assertSame(500, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
    }

    public function testUnknownMissingAndPreviouslyDeliveredCannotRefundEvenWhenTerminated(): void
    {
        $member = $this->member();
        $this->paidOrder($member);
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $task = \app\modules\service_instance\infrastructure\model\ProvisioningTaskModel::query()->where('order_unit_id', $unit)->firstOrFail();
        $input = ['member_id' => $member, 'source_id' => $unit, 'amount_minor' => 600, 'reason' => '未交付退款'];
        foreach (['queued', 'processing', 'waiting', 'attention', 'succeeded'] as $status) {
            $task->fill(['status' => $status])->save();
            $this->assertRejectedRefund($input);
            self::assertNotContains($unit, array_column($this->finance()->options($this->actor(), 'refunds', $member), 'id'));
        }
        $task->fill(['status' => 'failed', 'external_id' => 'external-created'])->save();
        $this->assertRejectedRefund($input);
        $task->fill(['external_id' => null, 'error_code' => 'operator_confirmed_not_created'])->save();
        $this->assertRejectedRefund($input, '无人工凭证的历史确认也不能退款');
        $task->fill(['not_created_by' => 1, 'not_created_at' => date('Y-m-d H:i:s'), 'not_created_reason' => '供应商核实未创建', 'not_created_reference' => 'CHECK-001'])->save();
        self::assertContains($unit, array_column($this->finance()->options($this->actor(), 'refunds', $member), 'id'));
        \app\modules\service_instance\infrastructure\model\ServiceInstanceModel::query()->whereKey($task->service_id)->update(['activated_at' => date('Y-m-d H:i:s')]);
        $this->assertRejectedRefund($input, '曾交付后终止也不能退款');
        $task->delete();
        $this->assertRejectedRefund($input, '缺失交付任务不能推断为未交付');
        self::assertSame(0, Db::table('refund_requests')->count());
        self::assertSame(300, (int) Db::table('wallet_accounts')->value('available_minor'));
    }

    public function testFullRefundRequiresIndependentReviewAndRechecksBeforeExecution(): void
    {
        $member = $this->member();
        $this->paidOrder($member);
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $input = ['member_id' => $member, 'source_id' => $unit, 'amount_minor' => 599, 'reason' => '未交付'];
        $this->assertRejectedRefund($input, '未交付不允许部分退款');
        $refund = $this->finance()->create($this->actor(), 'refunds', $this->input(array_replace($input, ['amount_minor' => 600])), ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
        self::assertNotNull(Db::table('refund_requests')->value('delivery_task_id'));
        self::assertSame('本机', Db::table('finance_operations')->where('resource', 'refunds')->value('client_ip_location'));
        foreach ([$this->actor(), $this->reviewer()] as $reviewer) {
            try {
                $this->finance()->act($reviewer, 'refunds', $refund['id'], 'approve', $this->input($reviewer->id === 1 ? ['review_reason' => '自己审核'] : []));
                self::fail('自己审核或无审核意见必须拒绝');
            } catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        }
        $this->finance()->act($this->reviewer(), 'refunds', $refund['id'], 'approve', $this->input(['review_reason' => '已核实未创建']));
        Db::table('service_provisioning_tasks')->where('order_unit_id', $unit)->update(['status' => 'attention']);
        try {
            $this->finance()->act($this->actor(), 'refunds', $refund['id'], 'execute', $this->input([]));
            self::fail('执行时证据变为未知必须拒绝');
        } catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame('approved', Db::table('refund_requests')->value('status'));
        self::assertSame(600, (int) Db::table('order_item_units')->where('id', $unit)->value('reserved_refund_minor'));
        self::assertSame(0, Db::table('wallet_entries')->where('entry_type', 'refund_credit')->count());
    }

    public function testWithdrawalReceiptIsUniqueAndFailedRegistrationKeepsFrozenFunds(): void
    {
        $member = $this->member();
        $recharge = $this->deposit($member);
        $base = ['member_id' => $member, 'source_id' => $recharge['id'], 'amount_minor' => 500, 'reason' => '退还未消费充值'];
        $first = $this->finance()->create($this->actor(), 'withdrawals', $this->input($base));
        $second = $this->finance()->create($this->actor(), 'withdrawals', $this->input($base));
        foreach ([$first, $second] as $record) $this->finance()->act($this->reviewer(), 'withdrawals', $record['id'], 'approve', $this->input(['review_reason' => '已核对原充值及冻结']));
        $payout = ['external_reference' => 'BANK-UNIQUE-001', 'payout_note' => '本日银行转账已核对'];
        $this->finance()->act($this->reviewer(), 'withdrawals', $first['id'], 'payout', $this->input($payout));
        foreach ([$payout, ['external_reference' => 'BANK-002']] as $invalid) {
            try {
                $this->finance()->act($this->reviewer(), 'withdrawals', $second['id'], 'payout', $this->input($invalid));
                self::fail('重复凭证或缺少核对说明必须拒绝');
            } catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        }
        self::assertSame('approved', Db::table('withdrawal_requests')->where('id', $second['id'])->value('status'));
        self::assertSame(500, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(500, (int) Db::table('wallet_accounts')->value('frozen_minor'));
        self::assertSame(1, Db::table('wallet_entries')->where('entry_type', 'withdrawal_payout')->count());
        self::assertSame(2, (int) Db::table('withdrawal_requests')->where('id', $first['id'])->value('executor_id'));
        self::assertSame($payout['payout_note'], Db::table('withdrawal_requests')->where('id', $first['id'])->value('payout_note'));
    }

    public function testMysqlRefundAndRetryCannotBothReserveTheSameInstance(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1' || !function_exists('pcntl_fork')) self::markTestSkipped('需要独立MySQL及pcntl');
        $member = $this->member();
        $order = $this->paidOrder($member);
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $task = Db::table('service_provisioning_tasks')->where('order_unit_id', $unit)->first();
        Db::table('service_provisioning_tasks')->where('id', $task->id)->update(['connection_encrypted' => 'fixture']);
        (new DatabaseTransactionManager())->run(fn () => $this->provisioner()->prepare($order, 'Test@123456'));
        $input = ['member_id' => $member, 'source_id' => $unit, 'amount_minor' => 600, 'reason' => '与重试竞争'];
        $results = $this->concurrently('refunds', [$this->input($input), ['_retry_service' => (int) $task->service_id]]);
        $codes = array_column($results, 'code'); sort($codes);
        self::assertSame([0, 409], $codes);
        $reserved = (int) Db::table('order_item_units')->where('id', $unit)->value('reserved_refund_minor');
        self::assertSame($reserved > 0 ? 'failed' : 'waiting', Db::table('service_provisioning_tasks')->where('id', $task->id)->value('status'));
        self::assertSame($reserved > 0 ? 1 : 0, Db::table('refund_requests')->count());
    }

    private function assertRejectedRefund(array $input, string $message = '非明确失败不能退款'): void
    {
        try { $this->finance()->create($this->actor(), 'refunds', $this->input($input)); self::fail($message); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
    }

    private function provisioner(): \app\modules\service_instance\application\ProvisionPaidOrderService
    {
        $gateway = new class implements \app\modules\provider\contract\ProviderProvisioningGatewayInterface {
            public function capture(array $binding, array $configuration): array { return []; }
            public function execute(array $task, string $password): \app\shared\plugin\dto\provider\ProvisionResult { throw new \LogicException('此测试禁止调用外部供应商'); }
        };
        return new \app\modules\service_instance\application\ProvisionPaidOrderService(new \app\modules\order\infrastructure\DatabaseOrderProvisioningRepository(),
            new \app\modules\service_instance\infrastructure\DatabaseProvisioningRepository(new \app\shared\infrastructure\SodiumSensitiveDataCipher(str_repeat('test-key-', 8))),
            $gateway, new DatabaseTransactionManager());
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
                    if (isset($input['_retry_service'])) {
                        $this->provisioner()->retry($input['_retry_service']);
                        $result = ['code' => 0];
                    } else {
                        $result = ['code' => 0] + $this->finance()->create($this->actor(), $resource, $input);
                    }
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
