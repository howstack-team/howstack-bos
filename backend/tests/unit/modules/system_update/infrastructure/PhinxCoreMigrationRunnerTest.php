<?php

declare(strict_types=1);

namespace tests\unit\modules\system_update\infrastructure;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\exception\SystemUpdateException;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 验证核心迁移可用于全新数据库、重复执行和旧安装基线接管。
 */
final class PhinxCoreMigrationRunnerTest extends TestCase
{
    private const MIGRATIONS = [
        20260921000100,
        20260925000100,
        20260925000200,
        20260925000300,
        20260926000100,
        20260926000200,
        20260926000300,
        20260926000400,
        20260926000500,
        20260926000600,
        20260926000700,
        20260926000800,
        20260927000100,
        20260927000200,
        20260927000300,
        20260927000400,
        20260927000500,
        20260927000600,
        20260927000700,
        20261001000100,
        20261001000200,
    ];

    /**
     * @var list<string> 测试结束后需要删除的 SQLite 文件
     */
    private array $databasePaths = [];

    /**
     * 清理迁移测试创建的临时数据库。
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->databasePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * 验证全新数据库会执行基础迁移且能够安全重复调用。
     * @return void
     */
    public function testMigratesFreshDatabaseAndIsIdempotent(): void
    {
        $configuration = $this->configuration();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());

        $before = $runner->status($configuration);
        self::assertSame(self::MIGRATIONS, $before->pending);
        $beforePdo = (new SystemUpdatePdoFactory())->connect($configuration);
        self::assertSame(
            0,
            (int) $beforePdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'test_migration_versions'",
            )->fetchColumn(),
        );

        $first = $runner->migrate($configuration);
        self::assertSame(self::MIGRATIONS, $first->executed);
        self::assertTrue($first->status->isUpToDate());

        $second = $runner->migrate($configuration);
        self::assertSame([], $second->executed);
        self::assertTrue($second->status->isUpToDate());

        $pdo = (new SystemUpdatePdoFactory())->connect($configuration);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('test_system_settings', $tables);
        self::assertContains('test_system_setting_categories', $tables);
        self::assertSame(
            [['code' => 'base', 'name' => '基础设置', 'sort_order' => 10], ['code' => 'security', 'name' => '安全设置', 'sort_order' => 20]],
            $pdo->query('SELECT code, name, sort_order FROM test_system_setting_categories ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC),
        );
        self::assertSame(12, (int) $pdo->query('SELECT COUNT(*) FROM test_system_settings WHERE category_id IS NOT NULL')->fetchColumn());
        self::assertSame(9, (int) $pdo->query("SELECT COUNT(*) FROM test_system_settings s INNER JOIN test_system_setting_categories c ON c.id = s.category_id WHERE c.code = 'base' AND s.is_public = 1")->fetchColumn());
        self::assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM test_system_settings s INNER JOIN test_system_setting_categories c ON c.id = s.category_id WHERE c.code = 'security' AND s.is_public = 0")->fetchColumn());
        self::assertSame('皓量云擎云业务运营系统', $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'site.title'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM test_system_settings WHERE setting_key = 'site.name'")->fetchColumn());
        self::assertContains('idx_setting_category', array_column($pdo->query('PRAGMA index_list(test_system_settings)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
        self::assertContains('test_admin_users', $tables);
        self::assertContains('test_installation', $tables);
        self::assertContains('test_system_update_runs', $tables);
        self::assertContains('test_admin_departments', $tables);
        self::assertContains('test_admin_roles', $tables);
        self::assertContains('test_admin_user_roles', $tables);
        self::assertContains('test_admin_role_permissions', $tables);
        self::assertContains('test_admin_role_departments', $tables);
        self::assertContains('test_admin_login_logs', $tables);
        self::assertContains('test_admin_operation_logs', $tables);
        self::assertContains('test_members', $tables);
        self::assertContains('test_service_instances', $tables);
        foreach (['ticket_types', 'ticket_quick_replies', 'tickets', 'ticket_messages', 'content_categories', 'content_entries'] as $table) {
            self::assertContains('test_' . $table, $tables);
        }
        $categoryColumns = array_column($pdo->query('PRAGMA table_info(test_content_categories)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('parent_id', $categoryColumns);
        self::assertNotContains('code', $categoryColumns);
        self::assertContains('test_member_groups', $tables);
        self::assertContains('test_member_levels', $tables);
        self::assertContains('test_member_real_name_authentications', $tables);
        foreach (['order_item_units', 'wallet_accounts', 'recharges', 'payment_transactions',
            'wallet_funding_lots', 'wallet_entries', 'wallet_consumptions', 'refund_requests', 'invoice_records',
            'withdrawal_requests', 'finance_outbox', 'payment_notifications', 'refund_attempts',
            'invoice_allocations'] as $table) {
            self::assertContains('test_' . $table, $tables);
        }
        $memberColumns = array_column($pdo->query('PRAGMA table_info(test_members)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('group_id', $memberColumns);
        self::assertContains('level_id', $memberColumns);
        self::assertContains('mobile', $memberColumns);
        self::assertNotContains('username', $memberColumns);
        $orderColumns = array_column($pdo->query('PRAGMA table_info(test_orders)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('member_mobile_snapshot', $orderColumns);
        self::assertNotContains('member_username_snapshot', $orderColumns);
        $operationColumns = $pdo->query('PRAGMA table_info(test_admin_operation_logs)')->fetchAll(PDO::FETCH_ASSOC);
        self::assertContains('request_params', array_column($operationColumns, 'name'));
        self::assertNotContains('request_body', array_column($operationColumns, 'name'));
        self::assertNotContains('response_body', array_column($operationColumns, 'name'));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM test_admin_departments WHERE system_key = 'root' AND parent_id IS NULL")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM test_admin_roles WHERE code = 'system_super_admin' AND is_system = 1")->fetchColumn());
        self::assertContains('test_migration_versions', $tables);
    }

    /**
     * 验证旧安装器已经创建的表不会被删除或覆盖。
     * @return void
     */
    public function testAdoptsExistingInstallationAsBaseline(): void
    {
        $configuration = $this->configuration();
        $pdo = (new SystemUpdatePdoFactory())->connect($configuration);
        $pdo->exec('CREATE TABLE test_system_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT NOT NULL, is_public BOOLEAN NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec("INSERT INTO test_system_settings (setting_key, setting_value, is_public, created_at, updated_at) VALUES
            ('site.name', '客户已设置的名称', 0, '2026-01-01 12:00:00', '2026-02-01 12:00:00'),
            ('security.login_attempts', '7', 0, '2026-01-01 12:00:00', '2026-02-01 12:00:00'),
            ('admin.path', '/admin-secret', 0, '2026-01-01 12:00:00', '2026-02-01 12:00:00'),
            ('legal.software_agreement_version', 'legacy-version', 0, '2026-01-01 12:00:00', '2026-02-01 12:00:00')");
        $pdo->exec('CREATE TABLE test_admin_users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE test_installation (id INTEGER PRIMARY KEY, version TEXT NOT NULL)');
        $pdo->exec("INSERT INTO test_installation (id, version) VALUES (1, '0.1.0')");

        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $result = $runner->migrate($configuration);

        self::assertSame(self::MIGRATIONS, $result->executed);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM test_system_settings WHERE setting_key = 'site.name'")->fetchColumn());
        self::assertSame('客户已设置的名称', $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'site.title'")->fetchColumn());
        self::assertSame('base', $pdo->query("SELECT c.code FROM test_system_settings s INNER JOIN test_system_setting_categories c ON s.category_id = c.id WHERE s.setting_key = 'site.title'")->fetchColumn());
        self::assertSame('7', $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'security.login_attempts'")->fetchColumn());
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM test_system_settings WHERE setting_key IN ('admin.path', 'legal.software_agreement_version') AND category_id IS NULL")->fetchColumn());
        self::assertSame(
            '0.1.0',
            $pdo->query('SELECT version FROM test_installation WHERE id = 1')->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'test_system_update_runs'",
            )->fetchColumn(),
        );

        // 模拟 DDL 完成、版本记录尚未落盘时重试，已有设置仍不可被默认值覆盖。
        $pdo->exec('DELETE FROM test_migration_versions WHERE version = 20260926000600');
        self::assertSame([20260926000600], $runner->migrate($configuration)->executed);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM test_system_setting_categories')->fetchColumn());
        self::assertSame(14, (int) $pdo->query('SELECT COUNT(*) FROM test_system_settings')->fetchColumn());
        self::assertSame('客户已设置的名称', $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'site.title'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM test_system_settings WHERE setting_key = 'site.name'")->fetchColumn());
        self::assertSame('7', $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'security.login_attempts'")->fetchColumn());
    }

    /**
     * 已安装系统更名时仅更新旧默认标题，保留自定义标题并可安全重试。
     * @return void
     */
    public function testRenamesOnlyLegacyDefaultTitleOnUpgrade(): void
    {
        $configuration = $this->configuration();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $runner->migrate($configuration);
        $pdo = (new SystemUpdatePdoFactory())->connect($configuration);
        $original = $pdo->query("SELECT id, category_id, is_public, created_at FROM test_system_settings WHERE setting_key = 'site.title'")->fetch(PDO::FETCH_ASSOC);

        foreach ([
            ['皓量云擎业务管理系统', '皓量云擎云业务运营系统'],
            ['客户自定义网站标题', '客户自定义网站标题'],
            ['', ''],
        ] as [$previous, $expected]) {
            // 模拟已执行旧迁移、尚未执行更名迁移的安装，以及版本记录落盘前的重试。
            $pdo->exec('DELETE FROM test_migration_versions WHERE version = 20261001000100');
            $update = $pdo->prepare("UPDATE test_system_settings SET setting_value = ?, updated_at = ? WHERE setting_key = 'site.title'");
            $update->execute([$previous, '2026-09-21 12:00:00']);

            self::assertSame([20261001000100], $runner->migrate($configuration)->executed);
            self::assertSame($expected, $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'site.title'")->fetchColumn());
            self::assertSame($original, $pdo->query("SELECT id, category_id, is_public, created_at FROM test_system_settings WHERE setting_key = 'site.title'")->fetch(PDO::FETCH_ASSOC));
            if ($previous !== '皓量云擎业务管理系统') {
                self::assertSame('2026-09-21 12:00:00', $pdo->query("SELECT updated_at FROM test_system_settings WHERE setting_key = 'site.title'")->fetchColumn());
            }

            $pdo->exec('DELETE FROM test_migration_versions WHERE version = 20261001000100');
            self::assertSame([20261001000100], $runner->migrate($configuration)->executed);
            self::assertSame($expected, $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'site.title'")->fetchColumn());
            self::assertSame([], $runner->migrate($configuration)->executed);
        }
    }

    /**
     * 新安全设置沿用安装环境中的 JWT 有效期，并收敛到可配置的分钟范围。
     * @return void
     */
    public function testSeedsLoginLifetimeFromEnvironment(): void
    {
        $previous = getenv('JWT_TTL');
        try {
            foreach (['5400' => '90', '1' => '5', '172800' => '1440', 'invalid' => '120'] as $ttl => $minutes) {
                putenv('JWT_TTL=' . $ttl);
                $configuration = $this->configuration();
                (new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector()))->migrate($configuration);
                $pdo = (new SystemUpdatePdoFactory())->connect($configuration);
                self::assertSame($minutes, $pdo->query("SELECT setting_value FROM test_system_settings WHERE setting_key = 'security.session_expire'")->fetchColumn());
            }
        } finally {
            putenv($previous === false ? 'JWT_TTL' : 'JWT_TTL=' . $previous);
        }
    }

    /**
     * 验证旧库有多个管理员时不会猜测创始管理员。
     * @return void
     */
    public function testRejectsAmbiguousLegacyFounder(): void
    {
        $configuration = $this->configuration();
        $pdo = (new SystemUpdatePdoFactory())->connect($configuration);
        $pdo->exec('CREATE TABLE test_admin_users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $pdo->exec("INSERT INTO test_admin_users (id, username) VALUES (1, 'first'), (2, 'second')");
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());

        $this->expectException(SystemUpdateException::class);
        $this->expectExceptionMessage('无法自动判定创始账号');
        $runner->migrate($configuration);
    }

    /**
     * 验证明确指定旧版创始管理员后才允许迁移多个管理员。
     * @return void
     */
    public function testMigratesExplicitLegacyFounder(): void
    {
        $configuration = $this->configuration();
        $pdo = (new SystemUpdatePdoFactory())->connect($configuration);
        $pdo->exec('CREATE TABLE test_admin_users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $pdo->exec("INSERT INTO test_admin_users (id, username) VALUES (1, 'first'), (2, 'second')");
        $previous = getenv('HLYQ_FOUNDER_ADMIN_ID');
        putenv('HLYQ_FOUNDER_ADMIN_ID=2');
        try {
            $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
            $runner->migrate($configuration);
            self::assertSame(0, (int) $pdo->query('SELECT is_founder FROM test_admin_users WHERE id = 1')->fetchColumn());
            self::assertSame(1, (int) $pdo->query('SELECT is_founder FROM test_admin_users WHERE id = 2')->fetchColumn());
            $rootId = (int) $pdo->query("SELECT id FROM test_admin_departments WHERE system_key = 'root'")->fetchColumn();
            $roleId = (int) $pdo->query("SELECT id FROM test_admin_roles WHERE code = 'system_super_admin'")->fetchColumn();
            self::assertSame($rootId, (int) $pdo->query('SELECT department_id FROM test_admin_users WHERE id = 2')->fetchColumn());
            self::assertSame($roleId, (int) $pdo->query('SELECT role_id FROM test_admin_user_roles WHERE admin_user_id = 2')->fetchColumn());
        } finally {
            putenv($previous === false ? 'HLYQ_FOUNDER_ADMIN_ID' : 'HLYQ_FOUNDER_ADMIN_ID=' . $previous);
        }
    }

    /**
     * 旧部门树的多个根节点升级后应统一挂在系统根部门下。
     * @return void
     */
    public function testReparentsLegacyRootDepartments(): void
    {
        $configuration = $this->configuration();
        $pdo = (new SystemUpdatePdoFactory())->connect($configuration);
        $pdo->exec('CREATE TABLE test_admin_departments (id INTEGER PRIMARY KEY, name TEXT NOT NULL, parent_id INTEGER NULL, status INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO test_admin_departments (id, name, parent_id, status, created_at, updated_at) VALUES
            (10, '旧部门甲', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (11, '旧部门乙', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (12, '下级部门', 10, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        (new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector()))->migrate($configuration);

        $rootId = (int) $pdo->query("SELECT id FROM test_admin_departments WHERE system_key = 'root'")->fetchColumn();
        self::assertSame($rootId, (int) $pdo->query('SELECT parent_id FROM test_admin_departments WHERE id = 10')->fetchColumn());
        self::assertSame($rootId, (int) $pdo->query('SELECT parent_id FROM test_admin_departments WHERE id = 11')->fetchColumn());
        self::assertSame(10, (int) $pdo->query('SELECT parent_id FROM test_admin_departments WHERE id = 12')->fetchColumn());
    }

    /**
     * 验证数据库记录了当前代码包缺失的迁移时禁止继续更新。
     * @return void
     */
    public function testRejectsMissingMigrationFile(): void
    {
        $configuration = $this->configuration();
        $connections = new SystemUpdatePdoFactory();
        $runner = new PhinxCoreMigrationRunner($connections, new PdoSchemaInspector());
        $runner->migrate($configuration);

        $pdo = $connections->connect($configuration);
        $pdo->exec(
            "INSERT INTO test_migration_versions"
            . " (version, migration_name, start_time, end_time, breakpoint)"
            . " VALUES (20260921999999, 'MissingMigration', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0)",
        );

        $this->expectException(SystemUpdateException::class);
        $this->expectExceptionMessage('缺失的迁移记录');

        $runner->migrate($configuration);
    }

    /**
     * 验证 Phinx 运行时环境包含 MySQL 适配器必需的数据库名称。
     * @return void
     * @throws \ReflectionException 迁移管理器私有工厂方法无法反射时抛出
     */
    public function testRuntimeEnvironmentContainsDatabaseName(): void
    {
        $configuration = $this->configuration();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $managerMethod = new ReflectionMethod($runner, 'manager');

        [$manager] = $managerMethod->invoke($runner, $configuration);
        $environment = $manager->getConfig()->getEnvironment('runtime');

        self::assertIsArray($environment);
        self::assertSame($configuration->database, $environment['name'] ?? null);
    }

    /**
     * 创建使用独立临时 SQLite 文件的迁移配置。
     * @return MigrationDatabaseConfiguration 数据库迁移连接配置
     */
    private function configuration(): MigrationDatabaseConfiguration
    {
        $path = tempnam(sys_get_temp_dir(), 'hlyq-migration-test-');
        self::assertNotFalse($path);
        $this->databasePaths[] = $path;

        return new MigrationDatabaseConfiguration('sqlite', $path, 'test_');
    }
}
