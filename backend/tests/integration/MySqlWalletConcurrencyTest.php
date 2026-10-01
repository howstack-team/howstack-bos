<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\infrastructure\DatabaseWalletLedgerRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 显式开关下使用独立 MySQL 库和独立 PHP 进程验证真实行锁。 */
final class MySqlWalletConcurrencyTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') self::markTestSkipped('需 HOWSTACK_MYSQL_TEST=1，SQLite 不验证行锁');
        $this->initializeDatabase();
    }

    public function testTwoProcessesPayingSameOrderDeductOnlyOnce(): void
    {
        $member = $this->member();
        $order = $this->order($member);
        (new WalletLedgerService(new DatabaseWalletLedgerRepository(), new DatabaseTransactionManager()))
            ->creditConfirmedRecharge($member, $this->recharge($member));
        $results = $this->race($member, [$order, $order]);
        self::assertSame([['balance' => 300], ['balance' => 300]], $results);
        self::assertSame(1, Db::table('wallet_entries')->where('entry_type', 'spend')->count());
        self::assertSame(1, Db::table('payment_transactions')->where('purpose', 'order')->count());
        self::assertSame(1, Db::table('finance_outbox')->count());
    }

    public function testTwoOrdersCannotOverspendSameAccount(): void
    {
        $member = $this->member();
        $first = $this->order($member, 'ORDER-ONE');
        $second = $this->order($member, 'ORDER-TWO');
        (new WalletLedgerService(new DatabaseWalletLedgerRepository(), new DatabaseTransactionManager()))
            ->creditConfirmedRecharge($member, $this->recharge($member));
        $results = $this->race($member, [$first, $second]);
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['balance'])));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => ($result['conflict'] ?? 0) === 409));
        self::assertSame(300, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(300, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
        self::assertSame(1, Db::table('orders')->where('payment_status', 'paid')->count());
        self::assertSame(1, Db::table('finance_outbox')->count());
    }

    /** @param list<int> $orders 并发请求订单 @return list<array<string, int>> */
    private function race(int $member, array $orders): array
    {
        $processes = [];
        $connection = Db::connection();
        $configuration = $connection->getConfig();
        // 父进程先持订单锁，确保两个 Worker 启动后真正竞争受保护的行。
        $connection->beginTransaction();
        foreach (array_unique($orders) as $order) Db::table('orders')->where('id', $order)->lockForUpdate()->first();
        try {
            foreach ($orders as $order) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/support/pay_order_worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
                fwrite($pipes[0], json_encode(['connection' => $configuration, 'member_id' => $member, 'order_id' => $order], JSON_THROW_ON_ERROR) . "\n");
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                self::assertSame("READY\n", fgets($pipes[1]));
            }
            $connection->commit();
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $line = fgets($pipes[1]);
                self::assertIsString($line);
                $results[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                fclose($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                self::assertSame('', $errors);
                self::assertSame(0, proc_close($process));
            }
            return $results;
        } finally {
            if ($connection->transactionLevel() > 0) $connection->rollBack();
            foreach ($processes as [$process]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        }
    }
}
