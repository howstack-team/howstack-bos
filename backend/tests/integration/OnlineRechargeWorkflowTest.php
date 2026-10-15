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

}
