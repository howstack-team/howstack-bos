<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\exception\SystemUpdateException;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实 MySQL 历史升级及 DDL 部分完成后的重试。 */
final class MySqlServiceOriginMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') self::markTestSkipped('需独立 MySQL 测试库');
        $this->initialMigrationVersion = 20261001000100;
        $this->initializeDatabase();
    }

    public function testLegacyUpgradeAndPartialDdlRetryPreserveAmountsAndForeignKeys(): void
    {
        [$service, $unit] = $this->legacyService();
        // 模拟 MySQL 新列已提交、后续 DDL 尚未执行的中断。
        Db::statement('ALTER TABLE orm_service_instances ADD origin_order_unit_id INT UNSIGNED NULL');
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261001000200], $runner->migrate($configuration)->executed);
        self::assertSame($unit, (int) Db::table('service_instances')->where('id', $service)->value('origin_order_unit_id'));
        self::assertSame(1200, (int) Db::table('order_item_units')->sum('allocated_minor'));
        $indexes = array_column(Db::select('SHOW INDEX FROM orm_order_item_units'), 'Key_name');
        self::assertContains('idx_order_unit_service', $indexes);
        self::assertNotContains('uk_order_unit_service', $indexes);
        $subsequent = $this->order((int) Db::table('service_instances')->value('member_id'), 'RENEW');
        $item = Db::table('order_items')->where('order_id', $subsequent)->value('id');
        Db::table('order_item_units')->where('order_item_id', $item)->where('unit_index', 1)->update(['service_id' => $service]);
        self::assertSame(2, Db::table('order_item_units')->where('service_id', $service)->count());
        // DDL 完成但版本未入账，重试应保留已确定的首次来源。
        Db::table('migration_versions')->where('version', 20261001000200)->delete();
        self::assertSame([20261001000200], $runner->migrate($configuration)->executed);
        self::assertSame($unit, (int) Db::table('service_instances')->value('origin_order_unit_id'));
        self::assertSame(2400, (int) Db::table('order_item_units')->sum('allocated_minor'));
        $foreignKeys = Db::select("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('orm_service_instances', 'orm_order_item_units') AND REFERENCED_TABLE_NAME IS NOT NULL", [$configuration->database]);
        self::assertContains('origin_order_unit_id', array_column($foreignKeys, 'COLUMN_NAME'));
        self::assertContains('service_id', array_column($foreignKeys, 'COLUMN_NAME'));
    }

    public function testOwnerMismatchIsRejectedAndRetrySucceedsAfterExplicitRepair(): void
    {
        [$service, $unit] = $this->legacyService();
        $owner = (int) Db::table('service_instances')->value('member_id');
        $other = $this->member('13800138002');
        Db::table('service_instances')->where('id', $service)->update(['member_id' => $other]);
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        try {
            $runner->migrate($configuration);
            self::fail('所属会员不一致的历史关联不能通过迁移');
        } catch (SystemUpdateException $error) {
            self::assertStringContainsString('服务订单关联无效', $error->getPrevious()->getMessage());
        }
        self::assertSame($other, (int) Db::table('service_instances')->value('member_id'));
        self::assertSame(1200, (int) Db::table('order_item_units')->sum('allocated_minor'));
        self::assertSame(0, (int) Db::selectOne("SELECT COUNT(*) AS aggregate FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'orm_service_instances' AND COLUMN_NAME = 'origin_order_unit_id'", [$configuration->database])->aggregate);
        Db::table('service_instances')->where('id', $service)->update(['member_id' => $owner]);
        self::assertSame([20261001000200], $runner->migrate($configuration)->executed);
        self::assertSame($unit, (int) Db::table('service_instances')->value('origin_order_unit_id'));
    }

    /** @return array{int, int} 首次服务及交易单位 */
    private function legacyService(): array
    {
        $member = $this->member();
        $this->order($member);
        $service = $this->row('service_instances', ['instance_no' => 'LEGACY-SERVICE', 'product_type' => 'cloud_server', 'member_id' => $member,
            'status' => 'active', 'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00']);
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        Db::table('order_item_units')->where('id', $unit)->update(['service_id' => $service]);
        return [$service, $unit];
    }
}
