<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class OnlinePaymentMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }
    protected function setUp(): void { $this->initialMigrationVersion = 20261015000200; $this->initializeDatabase(); }
    public function testExistingFinanceDataAndPrefixSurviveRepeatableUpgrade(): void
    {
        $member = $this->member(); $now = date('Y-m-d H:i:s');
        $id = Db::table('recharges')->insertGetId(['recharge_no' => 'HISTORICAL-001', 'member_id' => $member, 'amount_minor' => 199, 'currency' => 'CNY', 'channel' => 'offline', 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now]);
        $payment = Db::table('payment_transactions')->insertGetId(['payment_no' => 'HISTORICAL-PAY', 'member_id' => $member, 'purpose' => 'recharge', 'purpose_id' => $id, 'channel' => 'offline', 'channel_trade_no' => 'OLD-TRADE', 'amount_minor' => 199, 'currency' => 'CNY', 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now]);
        $before = Db::table('payment_transactions')->where('id', $payment)->first();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector()); $cfg = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertContains(20261015000300, $runner->migrate($cfg)->executed);
        self::assertEquals($before, Db::table('payment_transactions')->where('id', $payment)->first());
        self::assertSame([], $runner->migrate($cfg)->executed);
        if (Db::connection()->getDriverName() === 'mysql') {
            $columns = Db::select("SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orm_online_payment_attempts'");
            self::assertCount(24, $columns);
            foreach ($columns as $column) self::assertMatchesRegularExpression('/[\\x{4e00}-\\x{9fff}]/u', $column->COLUMN_COMMENT);
            self::assertSame('varchar(128)', Db::select("SHOW FULL COLUMNS FROM orm_payment_transactions LIKE 'channel_trade_no'")[0]->Type);
            self::assertCount(1, Db::select("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orm_online_payment_attempts'"));
        }
    }
}
