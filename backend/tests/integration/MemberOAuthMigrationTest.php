<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class MemberOAuthMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261015000100;
        $this->initializeDatabase();
    }

    public function testUpgradePreservesAccountsAndIsRepeatableWithChineseComments(): void
    {
        $id = $this->member();
        $before = Db::table('members')->where('id', $id)->first();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $config = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertContains(20261015000200, $runner->migrate($config)->executed);
        self::assertEquals($before, Db::table('members')->where('id', $id)->first());
        self::assertSame([], $runner->migrate($config)->executed);
        if (Db::connection()->getDriverName() === 'mysql') {
            $tables = Db::select("SELECT TABLE_COMMENT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('orm_member_oauth_identities', 'orm_member_oauth_pending')");
            self::assertCount(2, $tables);
            foreach ($tables as $table) {
                self::assertMatchesRegularExpression('/[\\x{4e00}-\\x{9fff}]/u', $table->TABLE_COMMENT);
                self::assertSame('utf8mb4_bin', $table->TABLE_COLLATION);
            }
            $columns = Db::select("SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('orm_member_oauth_identities', 'orm_member_oauth_pending')");
            self::assertCount(16, $columns);
            foreach ($columns as $column) self::assertMatchesRegularExpression('/[\\x{4e00}-\\x{9fff}]/u', $column->COLUMN_COMMENT);
        }
    }
}
