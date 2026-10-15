<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 验证已有开发数据升级、前缀、重复执行与MySQL原生注释。 */
final class MessageRuntimeMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private setUpDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261007000400;
        $this->setUpDatabase();
    }

    public function testForwardUpgradePreservesDataAndIsIdempotentWithComments(): void
    {
        self::assertFalse(Db::connection()->getSchemaBuilder()->hasTable('messages'));
        $before = Db::table('admin_users')->get()->map(static fn (object $row): array => (array) $row)->all();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner->migrate($configuration);
        $runner->migrate($configuration);
        self::assertSame($before, Db::table('admin_users')->get()->map(static fn (object $row): array => (array) $row)->all());
        foreach (['messages', 'message_recipients', 'message_deliveries', 'message_delivery_attempts'] as $table) {
            self::assertSame(0, Db::table($table)->count());
            if (getenv('HOWSTACK_MYSQL_TEST') !== '1') continue;
            $connection = Db::connection();
            $name = $connection->getTablePrefix() . $table;
            $columns = $connection->select('SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$connection->getDatabaseName(), $name]);
            self::assertNotEmpty($columns);
            foreach ($columns as $column) {
                self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->COLUMN_COMMENT, $name . '.' . $column->COLUMN_NAME);
            }
        }
    }
}
