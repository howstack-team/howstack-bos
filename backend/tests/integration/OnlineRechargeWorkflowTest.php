<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\finance\application\{OnlineRechargeService, WalletLedgerService};
use app\modules\finance\contract\OnlinePaymentGatewayInterface;
use app\modules\finance\infrastructure\{DatabaseOnlinePaymentRepository, DatabaseWalletLedgerRepository};
use app\modules\finance\infrastructure\model\{OnlinePaymentAttemptModel, PaymentTransactionModel, RechargeModel};
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use app\shared\plugin\dto\{Configuration, payment\PaymentResult, payment\PaymentNotification};
use app\shared\plugin\exception\PluginException;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory};

final class OnlineRechargeWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;
    use \tests\support\UndeliveredOrderFixture;

    private function fixture(): array
    {
        $member = $this->member();
        $gateway = new class implements OnlinePaymentGatewayInterface {
            public string $createStatus = 'pending', $queryStatus = 'pending', $closeStatus = 'closed';
            public bool $reject = false, $mismatch = false;
            public int $createCalls = 0, $queryCalls = 0;
            public function channels(): array { return [['id' => 7, 'name' => '支付宝', 'plugin_id' => 'alipay_order_code']]; }
            public function capture(int $channelId): array { return ['plugin_id' => 'alipay_order_code', 'package_hash' => str_repeat('a', 64), 'channel_revision' => 2, 'configuration' => new Configuration(['app_id' => 'original-app', 'secret' => 'fixture-secret'])]; }
            public function execute(string $operation, array $attempt): PaymentResult {
                if ($operation === 'create') $this->createCalls++; else $this->queryCalls++;
                $status = match ($operation) { 'create' => $this->createStatus, 'query' => $this->queryStatus, 'close' => $this->closeStatus };
                return new PaymentResult($status, $attempt['payment_no'], $attempt['amount_minor'], $status === 'paid' ? 'TRADE_' . $attempt['payment_id'] : null,
                    $status === 'pending' ? 'https://qr.alipay.com/fixture' : null);
            }
            public function verify(array $attempt, array|PaymentNotification $notification): PaymentResult {
                if ($this->reject) throw new PluginException('验签失败', 'invalid_notification');
                return new PaymentResult('paid', $attempt['payment_no'], $this->mismatch ? $attempt['amount_minor'] + 1 : $attempt['amount_minor'], 'TRADE_' . $attempt['payment_id']);
            }
        };
        $settings = $this->createStub(SystemSettingsRepositoryInterface::class);
        $settings->method('values')->willReturn(['site.url' => 'https://merchant.example.test']);
        $installations = $this->createStub(PluginInstallationRepositoryInterface::class);
        $installations->method('all')->willReturn(['alipay_order_code' => ['package_hash' => str_repeat('a', 64)]]);
        $cipher = new SodiumSensitiveDataCipher(str_repeat('fixture-key', 6));
        $repo = new DatabaseOnlinePaymentRepository($cipher, $installations);
        $tx = new DatabaseTransactionManager();
        $wallet = new WalletLedgerService(new DatabaseWalletLedgerRepository(), $tx, MessageRuntimeFactory::publisher());
        $service = new OnlineRechargeService($repo, $gateway, $wallet, $tx, new GetSystemSettingsService($settings));
        return [$member, $service, $gateway, $repo, $wallet, $cipher];
    }
    private function create(int $member, OnlineRechargeService $service, string $key = 'fixture-request-0001'): array {
        return $service->create($member, 7, 12345, $key, ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
    }
    private function rejected(int $status, callable $call): void {
        try { $call(); self::fail('应拒绝操作'); } catch (BusinessException $error) { self::assertSame($status, $error->httpStatus()); }
    }
    public function testCreatePinsEncryptedConfigurationAndIdempotentIntent(): void {
        [$member, $service, $gateway, $repo, , $cipher] = $this->fixture();
        $first = $this->create($member, $service);
        self::assertSame('pending', $first['status']);
        self::assertSame($first, $this->create($member, $service));
        self::assertSame(1, $gateway->createCalls);
        $row = $repo->request($member, 'fixture-request-0001');
        self::assertStringNotContainsString('fixture-secret', $row['configuration_encrypted']);
        self::assertStringContainsString('original-app', $cipher->decrypt($row['configuration_encrypted']));
        self::assertArrayNotHasKey('configuration_encrypted', $first);
        self::assertSame(1, Db::table('payment_transactions')->count());
        $this->rejected(409, fn () => $service->create($member, 7, 20000, 'fixture-request-0001', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']));
        self::assertSame(0, $service->listing($this->member('13800138002'), 1, 20)['total']);
        $this->rejected(404, fn () => $service->detail($member + 1, $first['id']));
    }
    public function testVerifiedNotificationCreditsExactlyOnceAndQueryCannotRegressPayment(): void {
        [$member, $service, $gateway, $repo, $wallet] = $this->fixture();
        $first = $this->create($member, $service);
        $row = $repo->request($member, 'fixture-request-0001');
        for ($i = 0; $i < 2; $i++) $service->notify($row['notify_token'], ['notify_id' => 'NOTIFY1'], 'signed-body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
        self::assertSame(12345, $wallet->balance($member)['available_minor']);
        self::assertSame(1, Db::table('wallet_entries')->count());
        self::assertSame(1, Db::table('wallet_funding_lots')->count());
        self::assertSame(1, Db::table('payment_notifications')->count());
        self::assertSame('本机', Db::table('payment_notifications')->value('client_ip_location'));
        self::assertSame('paid', $service->reconcile($member, $first['id'], true)['status']);
        self::assertSame('credited', RechargeModel::query()->first()->status);
    }
    public function testUnverifiedOrMismatchedResultsNeverCredit(): void {
        [$member, $service, $gateway, $repo, $wallet] = $this->fixture();
        $this->create($member, $service); $row = $repo->request($member, 'fixture-request-0001');
        $gateway->reject = true;
        try { $service->notify($row['notify_token'], ['notify_id' => 'N'], 'body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']); self::fail(); } catch (PluginException) {}
        $gateway->reject = false; $gateway->mismatch = true;
        $this->rejected(409, fn () => $service->notify($row['notify_token'], ['notify_id' => 'N'], 'body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']));
        self::assertSame(0, $wallet->balance($member)['available_minor']);
        self::assertSame(0, Db::table('payment_notifications')->count());
    }
    public function testUnknownCreateIsNeverRepeatedAndPaidQueryRecovers(): void {
        [$member, $service, $gateway, , $wallet] = $this->fixture(); $gateway->createStatus = 'unknown';
        $first = $this->create($member, $service);
        self::assertSame('unknown', $first['status']);
        $this->create($member, $service); self::assertSame(1, $gateway->createCalls);
        $gateway->queryStatus = 'paid';
        self::assertSame('paid', $service->reconcile($member, $first['id'])['status']);
        self::assertSame(12345, $wallet->balance($member)['available_minor']);
    }
    public function testCloseRacePaidResultAndVerifiedLatePaymentAreHonored(): void {
        [$member, $service, $gateway, $repo, $wallet] = $this->fixture();
        $first = $this->create($member, $service); $gateway->closeStatus = 'unknown'; $gateway->queryStatus = 'paid';
        self::assertSame('paid', $service->reconcile($member, $first['id'], true)['status']);
        self::assertSame(12345, $wallet->balance($member)['available_minor']);
        $second = $this->create($member, $service, 'fixture-request-0002'); $gateway->queryStatus = 'pending'; $gateway->closeStatus = 'closed';
        self::assertSame('closed', $service->reconcile($member, $second['id'], true)['status']);
        $row = $repo->request($member, 'fixture-request-0002');
        $service->notify($row['notify_token'], ['notify_id' => 'LATE'], 'body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
        self::assertSame(24690, $wallet->balance($member)['available_minor']);
    }
    public function testLeaseRecoveryChecksOriginalTransactionAndAutoExpiryClosesOnlyWithEvidence(): void {
        [$member, $service, $gateway, $repo] = $this->fixture(); $gateway->createStatus = 'unknown';
        $first = $this->create($member, $service);
        OnlinePaymentAttemptModel::query()->update(['next_check_at' => '2000-01-01 00:00:00', 'expires_at' => '2000-01-01 00:00:00']);
        $service->tick(); self::assertSame('closed', $service->detail($member, $first['id'])['status']);
        self::assertSame(1, $gateway->createCalls);
        $gateway->queryStatus = 'unknown'; $this->create($member, $service, 'fixture-request-0002');
        OnlinePaymentAttemptModel::query()->where('request_key', 'fixture-request-0002')->update(['reconcile_until' => '2000-01-01 00:00:00', 'next_check_at' => '2000-01-01 00:00:00']);
        $service->tick();
        self::assertSame('manual_review', $repo->request($member, 'fixture-request-0002')['last_error_code']);
    }
    public function testMysqlConcurrentNotificationsAndQueryDoNotDoubleCredit(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1' || !function_exists('pcntl_fork')) self::markTestSkipped('需要MySQL和独立进程');
        [$member, $service, $gateway, $repo, $wallet] = $this->fixture();
        $row = $this->create($member, $service); $snapshot = $repo->request($member, 'fixture-request-0001');
        $gateway->queryStatus = 'paid';
        $this->testCapsule->getConnection()->disconnect(); $this->mysqlServer = null;
        $signal = tempnam(sys_get_temp_dir(), 'pay-signal-'); unlink($signal); $files = []; $children = [];
        for ($i = 0; $i < 3; $i++) {
            $file = tempnam(sys_get_temp_dir(), 'pay-proof-'); $files[] = $file;
            $pid = pcntl_fork();
            if ($pid === -1) throw new \RuntimeException('创建并发测试失败');
            if ($pid === 0) {
                while (!file_exists($signal)) usleep(1000);
                try {
                    Db::statement('SET SESSION innodb_lock_wait_timeout = 5');
                    if ($i === 2) $service->reconcile($member, $row['id']);
                    else $service->notify($snapshot['notify_token'], ['notify_id' => 'SAME_NOTIFY'], 'signed-body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
                    file_put_contents($file, 'ok');
                } catch (\Throwable $e) { file_put_contents($file, get_class($e) . ': ' . ($e instanceof BusinessException ? $e->getMessage() : '不可预期错误')); }
                $this->testCapsule->getConnection()->disconnect(); exit(0);
            }
            $children[] = $pid;
        }
        touch($signal);
        try {
            foreach ($children as $pid) { pcntl_waitpid($pid, $code); self::assertSame(0, pcntl_wexitstatus($code)); }
            foreach ($files as $file) self::assertSame('ok', file_get_contents($file));
            self::assertSame(12345, $wallet->balance($member)['available_minor']);
            self::assertSame(1, Db::table('wallet_entries')->count()); self::assertSame(1, Db::table('wallet_funding_lots')->count());
            self::assertSame(1, Db::table('payment_notifications')->count());
        } finally {
            $mysql = (require dirname(__DIR__, 2) . '/config/database.php')['connections']['mysql'];
            $this->mysqlServer = new \PDO('mysql:host=' . $mysql['host'] . ';port=' . $mysql['port'], $mysql['username'], $mysql['password']);
            unlink($signal); foreach ($files as $file) unlink($file);
        }
    }

    public function testReadOnlyAcceptanceReportChecksPaidEvidenceWithoutExposingSecrets(): void
    {
        [$member, $service, $gateway, $repo] = $this->fixture();
        $first = $this->create($member, $service);
        $row = $repo->request($member, 'fixture-request-0001');
        $checker = $this->acceptanceChecker($gateway);
        $before = $this->paymentEvidenceSnapshot();
        $pending = $checker->check((int) $row['payment_id']);
        self::assertSame($before, $this->paymentEvidenceSnapshot(), '读取不能建钱包或修改支付状态');
        self::assertSame('pass', $pending['transaction']['local_consistency']);
        self::assertSame('not_confirmed', $pending['transaction']['collection_evidence']);
        self::assertSame('pending', $pending['external_verification']);
        $service->notify($row['notify_token'], ['notify_id' => 'AUDIT_NOTICE'], 'body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
        $before = $this->paymentEvidenceSnapshot();
        $paid = $checker->check((int) $row['payment_id']);
        self::assertSame($before, $this->paymentEvidenceSnapshot(), '核对不能改余额、批次或流水');
        self::assertSame('pass', $paid['transaction']['local_consistency']);
        self::assertSame('local_paid', $paid['transaction']['collection_evidence']);
        self::assertSame('pending', $paid['external_verification'], '本地一致不等于完成真实商户验收');
        self::assertSame(1, $gateway->createCalls);
        self::assertSame(0, $gateway->queryCalls, '预检不得调用上游查询或关单');
        $output = json_encode($paid, JSON_THROW_ON_ERROR);
        foreach (['fixture-secret', 'configuration_encrypted', 'notify_token', $row['notify_token'], 'TRADE_' . $row['payment_id']] as $secret) self::assertStringNotContainsString($secret, $output);
        self::assertSame($first['amount_minor'], $paid['transaction']['amount_minor']);
        self::assertSame(1, $paid['transaction']['processed_notifications']);
    }

    public function testAcceptanceReportAccountsForConsumptionRefundFreezeAndPayout(): void
    {
        [$member, $service, $gateway, $repo, $wallet] = $this->fixture();
        $this->create($member, $service);
        $row = $repo->request($member, 'fixture-request-0001');
        $service->notify($row['notify_token'], ['notify_id' => 'FLOW_NOTICE'], 'body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
        $order = $this->order($member);
        $tx = new DatabaseTransactionManager();
        (new \app\modules\order\application\PayOrderByWalletService(new \app\modules\order\infrastructure\DatabaseOrderPaymentRepository(),
            $wallet, $tx, new \app\modules\finance\infrastructure\DatabaseOutboxWriter(), MessageRuntimeFactory::publisher(),
            \tests\support\ProductRuntimeFactory::purchases(), new \tests\support\RecordingOrderProvisioning()))->pay($member, $order);
        $checker = $this->acceptanceChecker($gateway);
        self::assertSame('pass', $checker->check($row['payment_id'])['transaction']['local_consistency']);
        // 使用公开财务用例冻结、驳回及登记打款，核对不能把来源减少误判为丢账。
        $guard = new \app\modules\service_instance\infrastructure\DatabaseUndeliveredRefundGuard();
        $finance = new \app\modules\finance\application\ManageFinanceService(
            new \app\modules\admin\access\application\AdminAccessControlService(new \app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository()),
            new \app\modules\finance\infrastructure\DatabaseFinanceWorkflowRepository($guard), new DatabaseWalletLedgerRepository(), $wallet,
            new \app\modules\order\infrastructure\DatabaseOrderFinanceRepository(\tests\support\ProductRuntimeFactory::purchases()),
            $tx, new \app\modules\finance\infrastructure\DatabaseOutboxWriter(), $guard);
        $actor = new \app\modules\admin\auth\domain\AdminAccount(1, 'fixture', '测试管理员', '', 1, null, true);
        $reviewer = new \app\modules\admin\auth\domain\AdminAccount(2, 'reviewer', '复核人', '', 1, null, true);
        $this->row('admin_users', ['id' => 2, 'username' => 'reviewer', 'nickname' => '复核人', 'password_hash' => 'hash', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->confirmFixtureUndelivered($order);
        $unitId = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $refund = $finance->create($actor, 'refunds', ['request_key' => str_repeat('f', 16), 'member_id' => $member,
            'source_id' => $unitId, 'amount_minor' => 600, 'reason' => '确认未交付']);
        $finance->act($reviewer, 'refunds', $refund['id'], 'approve', ['request_key' => str_repeat('v', 16), 'review_reason' => '已核对未创建']);
        $finance->act($actor, 'refunds', $refund['id'], 'execute', ['request_key' => str_repeat('e', 16)]);
        self::assertSame('pass', $checker->check($row['payment_id'])['transaction']['local_consistency']);
        $request = ['request_key' => str_repeat('w', 16), 'member_id' => $member, 'source_id' => $row['recharge_id'], 'amount_minor' => 500, 'reason' => '退还未消费充值'];
        $withdrawal = $finance->create($actor, 'withdrawals', $request);
        self::assertSame('pass', $checker->check($row['payment_id'])['transaction']['local_consistency']);
        $finance->act($reviewer, 'withdrawals', $withdrawal['id'], 'reject', ['request_key' => str_repeat('r', 16), 'review_reason' => '核对后重新申请']);
        self::assertSame('pass', $checker->check($row['payment_id'])['transaction']['local_consistency']);
        $withdrawal = $finance->create($actor, 'withdrawals', array_replace($request, ['request_key' => str_repeat('n', 16)]));
        $finance->act($reviewer, 'withdrawals', $withdrawal['id'], 'approve', ['request_key' => str_repeat('a', 16), 'review_reason' => '已核对来源']);
        $finance->act($reviewer, 'withdrawals', $withdrawal['id'], 'payout', ['request_key' => str_repeat('p', 16), 'external_reference' => 'AUDIT-PAYOUT', 'payout_note' => '已核对银行合成凭证']);
        self::assertSame('pass', $checker->check($row['payment_id'])['transaction']['local_consistency']);
        Db::table('wallet_accounts')->where('member_id', $member)->increment('available_minor', 1);
        $before = $this->paymentEvidenceSnapshot();
        $broken = $checker->check($row['payment_id']);
        self::assertSame('fail', $broken['transaction']['local_consistency']);
        self::assertSame($before, $this->paymentEvidenceSnapshot(), '发现差错不能自动补账掩盖问题');
    }

    public function testPaymentPreflightAndCommandNeverClaimsExternalAcceptancePassed(): void
    {
        [, , $gateway] = $this->fixture();
        foreach (['http://127.0.0.1', 'https://localhost', 'https://127.0.0.1', 'https://merchant.example.test', 'https://merchant.example',
            'https://pay.acme.org/path', 'https://user:secret@pay.acme.org', 'https://pay.acme.org?secret=value'] as $root) {
            $report = $this->acceptanceChecker($gateway, $root)->check();
            self::assertFalse($report['ready_for_external_test'], $root);
            self::assertSame('pending', $report['external_verification']);
            self::assertStringNotContainsString('secret', json_encode($report, JSON_THROW_ON_ERROR));
        }
        $checker = $this->acceptanceChecker($gateway);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \app\command\CheckPaymentAcceptance($checker));
        self::assertSame(0, $tester->execute(['--json' => true]));
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['ready_for_external_test']);
        self::assertSame('not_tested', $report['callback_reachability']);
        self::assertSame('not_tested', $report['merchant_bill_match']);
        self::assertSame('pending', $report['external_verification']);
        self::assertSame(2, $tester->execute(['payment-id' => '1abc']));
        self::assertSame(1, $tester->execute(['payment-id' => '999999']));
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(0, $gateway->queryCalls);
    }

    public function testDuplicateCreditAndIncompleteSourceFailReadOnlyAudit(): void
    {
        [$member, $service, $gateway, $repo] = $this->fixture();
        $this->create($member, $service); $row = $repo->request($member, 'fixture-request-0001');
        $service->notify($row['notify_token'], ['notify_id' => 'DUPLICATE_AUDIT'], 'body', ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']);
        $entry = (array) Db::table('wallet_entries')->where('member_id', $member)->first();
        unset($entry['id']); $entry['entry_no'] = 'DUPLICATE-CREDIT'; $entry['entry_type'] = 'legacy_credit';
        Db::table('wallet_entries')->insert($entry);
        $checker = $this->acceptanceChecker($gateway);
        $report = $checker->check($row['payment_id']);
        self::assertSame('fail', $report['transaction']['local_consistency']);
        $single = array_values(array_filter($report['transaction']['checks'], static fn (array $check): bool => $check['code'] === 'single_credit'));
        self::assertSame('fail', $single[0]['status']);
        Db::table('wallet_entries')->where('entry_no', 'DUPLICATE-CREDIT')->delete();
        Db::table('wallet_funding_lots')->where('recharge_id', $row['recharge_id'])->decrement('remaining_minor', 1);
        self::assertSame('fail', $checker->check($row['payment_id'])['transaction']['local_consistency']);
    }

    public function testNoConfiguredChannelLeavesAcceptanceBlocked(): void
    {
        $gateway = $this->createMock(OnlinePaymentGatewayInterface::class);
        $gateway->expects(self::exactly(2))->method('channels')->willReturn([]);
        foreach (['capture', 'execute', 'verify'] as $method) {
            $gateway->expects(self::never())->method($method);
        }
        $checker = $this->acceptanceChecker($gateway);
        self::assertFalse($checker->check()['ready_for_external_test']);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \app\command\CheckPaymentAcceptance($checker));
        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame('pending', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['external_verification']);
    }

    /** @param OnlinePaymentGatewayInterface $gateway 测试通道 @param string $root 平台根地址 @return \app\modules\finance\application\CheckPaymentAcceptanceService 真实只读验收用例 */
    private function acceptanceChecker(OnlinePaymentGatewayInterface $gateway, string $root = 'https://pay.acme.org'): \app\modules\finance\application\CheckPaymentAcceptanceService
    {
        $settings = $this->createStub(SystemSettingsRepositoryInterface::class);
        $settings->method('values')->willReturn(['site.url' => $root]);
        return new \app\modules\finance\application\CheckPaymentAcceptanceService($gateway, new GetSystemSettingsService($settings),
            new \app\modules\finance\infrastructure\DatabasePaymentAcceptanceReadRepository());
    }

    /** @return array<string,string> 用于证明只读操作不会改变财务表 */
    private function paymentEvidenceSnapshot(): array
    {
        $snapshot = [];
        foreach (['recharges', 'payment_transactions', 'online_payment_attempts', 'payment_notifications', 'wallet_accounts', 'wallet_funding_lots', 'wallet_entries', 'wallet_consumptions', 'withdrawal_requests'] as $table) {
            $snapshot[$table] = Db::table($table)->get()->toJson();
        }
        return $snapshot;
    }

}
