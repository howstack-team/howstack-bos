<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\exception\SystemUpdateException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** 旧结构回填、重复升级及无效数据拒绝；不猜测首次来源。 */
final class ServiceOriginMigrationTest extends TestCase
{
    private string $path;
    private MigrationDatabaseConfiguration $configuration;
    private PhinxCoreMigrationRunner $runner;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'howstack-origin-');
        $this->configuration = new MigrationDatabaseConfiguration('sqlite', $this->path, 'custom_');
        $this->runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        [$manager] = (new ReflectionMethod($this->runner, 'manager'))->invoke($this->runner, $this->configuration);
        $manager->migrate('runtime', 20261001000100);
        $this->pdo = (new SystemUpdatePdoFactory())->connect($this->configuration);
        $now = '2026-10-01 08:30:00';
        $this->pdo->exec("INSERT INTO custom_members(id,mobile,nickname,password_hash,created_at,updated_at) VALUES (1,'13800138001','会员','hash','$now','$now')");
        $this->pdo->exec("INSERT INTO custom_orders(id,order_no,member_id,member_mobile_snapshot,member_nickname_snapshot,order_type,status,payment_status,created_at,updated_at) VALUES(1,'O1',1,'13800138001','会员','purchase','paid','paid','$now','$now')");
        $this->pdo->exec("INSERT INTO custom_order_items(id,order_id,line_no,product_type,product_name,quantity,created_at) VALUES(1,1,1,'cloud_server','云服务器',1,'$now')");
        $this->pdo->exec("INSERT INTO custom_service_instances(id,instance_no,product_type,member_id,status,created_at,updated_at) VALUES(1,'S1','cloud_server',1,'active','$now','$now'),(2,'S2','cloud_server',1,'active','$now','$now')");
        $this->pdo->exec("INSERT INTO custom_order_item_units(id,order_item_id,unit_index,service_id,allocated_minor,created_at) VALUES(1,1,1,1,1200,'$now')");
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        unlink($this->path);
    }

    public function testUpgradeBackfillsOriginAndAllowsSubsequentOrdersWithoutChangingOrigin(): void
    {
        $result = $this->runner->migrate($this->configuration);
        self::assertSame([20261001000200], $result->executed);
        self::assertSame(1, (int) $this->pdo->query('SELECT origin_order_unit_id FROM custom_service_instances WHERE id=1')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT origin_order_unit_id FROM custom_service_instances WHERE id=2')->fetchColumn());
        $this->pdo->exec("INSERT INTO custom_order_item_units(id,order_item_id,unit_index,service_id,allocated_minor,created_at) VALUES(2,1,2,1,200,'2026-10-01 09:00:00'),(3,1,3,1,100,'2026-10-01 10:00:00')");
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM custom_order_item_units WHERE service_id=1')->fetchColumn());
        self::assertSame([], $this->runner->migrate($this->configuration)->executed);
        // 模拟 DDL 已完成但迁移版本记录尚未提交后的重试。
        $this->pdo->exec('DELETE FROM custom_migration_versions WHERE version=20261001000200');
        self::assertSame([20261001000200], $this->runner->migrate($this->configuration)->executed);
        self::assertSame(1, (int) $this->pdo->query('SELECT origin_order_unit_id FROM custom_service_instances WHERE id=1')->fetchColumn());
        self::assertSame(1500, (int) $this->pdo->query('SELECT SUM(allocated_minor) FROM custom_order_item_units')->fetchColumn());
    }

    public function testInvalidHistoryFailsBeforeSchemaChangeAndCanBeRetriedAfterOperatorRepair(): void
    {
        $this->pdo->exec('UPDATE custom_order_item_units SET service_id=999 WHERE id=1');
        try {
            $this->runner->migrate($this->configuration);
            self::fail('无效历史关联不能自动迁移');
        } catch (SystemUpdateException $error) {
            self::assertStringContainsString('服务订单关联无效', $error->getPrevious()->getMessage());
        }
        self::assertNotContains('origin_order_unit_id', array_column($this->pdo->query('PRAGMA table_info(custom_service_instances)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
        self::assertSame(999, (int) $this->pdo->query('SELECT service_id FROM custom_order_item_units WHERE id=1')->fetchColumn());
        // 测试用数据由测试明确修复，产品迁移本身不改客户数据。
        $this->pdo->exec('UPDATE custom_order_item_units SET service_id=1 WHERE id=1');
        self::assertSame([20261001000200], $this->runner->migrate($this->configuration)->executed);
    }
}
