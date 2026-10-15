<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\exception\SystemUpdateException;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 使用真实 MySQL 验证注释、完整字段属性及 DDL 中断恢复，不访问安装数据库。 */
final class DatabaseSchemaCommentsMigrationTest extends TestCase
{
    // 此组只核对20261003注释迁移涉及的历史表，后续新表另有迁移测试。
    private const LATER_TABLES = ['business_plugin_installations', 'business_plugin_events', 'official_platform_state', 'software_installation_jobs',
        'channel_connections', 'channel_category_settings', 'mail_delivery_attempts', 'message_configurations'];

    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') {
            self::markTestSkipped('数据库注释需独立 MySQL 测试库验证');
        }
        $this->initialMigrationVersion = 20261003000200;
        $this->initializeDatabase();
    }

    public function testUpgradePreservesColumnAttributesIndexesForeignKeysAndBusinessData(): void
    {
        $pdo = Db::connection()->getPdo();
        $member = $this->member();
        $this->order($member);
        $recharge = $this->recharge($member, 2500);
        $now = '2026-10-03 08:00:00';
        $account = $this->row('wallet_accounts', ['member_id' => $member, 'currency' => 'CNY', 'fund_type' => 'recharge_cash',
            'available_minor' => 2500, 'frozen_minor' => 300, 'created_at' => $now, 'updated_at' => $now]);
        $this->row('wallet_funding_lots', ['account_id' => $account, 'recharge_id' => $recharge, 'credited_minor' => 2500, 'remaining_minor' => 2500, 'created_at' => $now]);
        $this->row('wallet_entries', ['entry_no' => 'COMMENT-FIXTURE', 'account_id' => $account, 'member_id' => $member,
            'entry_type' => 'credit', 'source_type' => 'recharge', 'source_id' => $recharge, 'amount_minor' => 2500, 'available_after_minor' => 2500, 'created_at' => $now]);

        // 客户属性必须保留：表达式默认值、特殊排序规则、转义、ON UPDATE 和已有中文。
        $pdo->exec("ALTER TABLE orm_admin_users MODIFY description VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT (concat('COMMENT ', 'O''Reilly, \\ncustomer')) COMMENT 'legacy COMMENT \\'note\\''");
        $pdo->exec('ALTER TABLE orm_admin_users MODIFY updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)');
        $pdo->exec("ALTER TABLE orm_admin_users MODIFY nickname VARCHAR(255) NOT NULL COMMENT '客户自定义昵称说明', ADD customer_reference VARCHAR(30) NULL COMMENT 'customer extension', COMMENT = '客户系统用户说明'");
        $pdo->exec("CREATE TABLE orm_plugin_example (id INT PRIMARY KEY COMMENT 'plugin field') ENGINE=InnoDB COMMENT='plugin table'");
        $before = $this->snapshot($pdo);

        $runner = $this->runner();
        self::assertSame([20261003000300, 20261004000100, 20261004000200, 20261006000100, 20261006000200, 20261006000300, 20261006000400, 20261007000100, 20261007000200, 20261007000300], $runner->migrate($this->configuration())->executed);
        $this->assertCoreComments($pdo, 'orm_', ['admin_users.customer_reference']);
        self::assertSame($before, $this->snapshot($pdo), '字段、索引、外键、表属性或业务数据被注释迁移改变');
        self::assertSame('客户自定义昵称说明', $this->columnComment($pdo, 'orm_admin_users', 'nickname'));
        self::assertSame('customer extension', $this->columnComment($pdo, 'orm_admin_users', 'customer_reference'));
        self::assertSame('plugin field', $this->columnComment($pdo, 'orm_plugin_example', 'id'));
        self::assertSame('客户系统用户说明', $pdo->query("SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orm_admin_users'")->fetchColumn());
        self::assertSame([], $runner->migrate($this->configuration())->executed);

        // DDL 已完成但版本记录未提交的重试，不应改写客户说明或再次影响业务数据。
        Db::table('migration_versions')->where('version', 20261003000300)->delete();
        self::assertSame([20261003000300], $runner->migrate($this->configuration())->executed);
        self::assertSame($before, $this->snapshot($pdo));
    }

    public function testFreshInstallationSupportsCustomPrefixes(): void
    {
        $configuration = $this->configuration();
        $server = new PDO('mysql:host=' . $configuration->host . ';port=' . $configuration->port, $configuration->username, $configuration->password);
        foreach (['test_', 'customer_'] as $prefix) {
            $database = 'howstack_comments_test_' . bin2hex(random_bytes(8));
            $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            try {
                $config = MigrationDatabaseConfiguration::fromArray(array_replace(Db::connection()->getConfig(), ['database' => $database, 'prefix' => $prefix]));
                $runner = $this->runner();
                $result = $runner->migrate($config);
                self::assertContains(20261003000300, $result->executed);
                self::assertTrue($result->status->isUpToDate());
                $pdo = (new SystemUpdatePdoFactory())->connect($config);
                $this->assertCoreComments($pdo, $prefix);
                self::assertSame([], $runner->migrate($config)->executed);
                $pdo = null;
            } finally {
                $server->exec('DROP DATABASE `' . $database . '`');
            }
        }
    }

    public function testMetadataLockFailureCanResumeWithoutOverwritingCompletedComments(): void
    {
        $configuration = $this->configuration();
        $pdo = Db::connection()->getPdo();
        $before = $this->snapshot($pdo);
        $blocker = (new SystemUpdatePdoFactory())->connect($configuration);
        $blocker->beginTransaction();
        $blocker->query('SELECT id FROM orm_admin_roles LIMIT 1')->fetchColumn();
        try {
            $this->runner()->migrate($configuration);
            self::fail('元数据锁未导致迁移中止');
        } catch (SystemUpdateException $error) {
            self::assertInstanceOf(\PDOException::class, $error->getPrevious());
            self::assertSame(1205, $error->getPrevious()->errorInfo[1]);
        } finally {
            $blocker->rollBack();
        }
        self::assertNotSame('', $this->columnComment($pdo, 'orm_admin_departments', 'id'));
        self::assertSame('', $this->columnComment($pdo, 'orm_wallet_accounts', 'id'));
        self::assertSame(0, Db::table('migration_versions')->where('version', 20261003000300)->count());
        self::assertSame($before, $this->snapshot($pdo));
        self::assertSame([20261003000300, 20261004000100, 20261004000200, 20261006000100, 20261006000200, 20261006000300, 20261006000400, 20261007000100, 20261007000200, 20261007000300], $this->runner()->migrate($configuration)->executed);
        $this->assertCoreComments($pdo, 'orm_');
        self::assertSame($before, $this->snapshot($pdo));
    }

    public function testNoBackslashEscapesAndPreflightFailurePreserveDefinitions(): void
    {
        $configuration = $this->configuration();
        $pdo = Db::connection()->getPdo();
        $pdo->exec("ALTER TABLE orm_compute_flavor_groups MODIFY cpu_model VARCHAR(128) NOT NULL DEFAULT 'COMMENT, O''Reilly' COMMENT 'old CPU note'");
        $pdo->exec('ALTER TABLE orm_compute_flavor_groups DROP boost_clock_mhz');
        try {
            $this->runner()->migrate($configuration);
            self::fail('缺失核心字段不应继续注释迁移');
        } catch (SystemUpdateException $error) {
            self::assertStringContainsString('核心字段缺失', $error->getPrevious()->getMessage());
        }
        self::assertSame('', $this->columnComment($pdo, 'orm_admin_departments', 'id'), '预检失败前不应执行任何 ALTER');
        $pdo->exec('ALTER TABLE orm_compute_flavor_groups ADD boost_clock_mhz INT UNSIGNED NULL');
        $before = $this->snapshot($pdo);
        // 在同一个实际迁移连接测试特殊 SQL 模式以及会话设置恢复。
        $runner = $this->runner();
        [$manager] = (new \ReflectionMethod($runner, 'manager'))->invoke($runner, $configuration);
        $migrationPdo = $manager->getEnvironment('runtime')->getAdapter()->getConnection();
        $migrationPdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES'");
        $migrationPdo->exec('SET SESSION sql_quote_show_create=0, SESSION lock_wait_timeout=17');
        $manager->migrate('runtime');
        self::assertSame(17, (int) $migrationPdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn());
        self::assertSame(0, (int) $migrationPdo->query('SELECT @@SESSION.sql_quote_show_create')->fetchColumn());
        self::assertStringContainsString('NO_BACKSLASH_ESCAPES', $migrationPdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        $this->assertCoreComments($pdo, 'orm_');
        self::assertSame($before, $this->snapshot($pdo));
    }

    private function configuration(): MigrationDatabaseConfiguration
    {
        return MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
    }

    private function runner(): PhinxCoreMigrationRunner
    {
        return new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
    }

    private function columnComment(PDO $pdo, string $table, string $column): string
    {
        $query = $pdo->prepare('SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table, $column]);
        return (string) $query->fetchColumn();
    }

    /** @param list<string> $extensions 客户扩展字段不属于核心注释清单。 */
    private function assertCoreComments(PDO $pdo, string $prefix, array $extensions = []): void
    {
        $tables = $pdo->query('SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_KEY_PAIR);
        $columns = $pdo->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        $missing = [];
        $tableCount = 0;
        $columnCount = 0;
        foreach ($tables as $table => $comment) {
            if (!str_starts_with($table, $prefix) || $table === $prefix . 'plugin_example'
                || in_array(substr($table, strlen($prefix)), self::LATER_TABLES, true)) continue;
            ++$tableCount;
            if (preg_match('/\p{Han}/u', $comment) !== 1) $missing[] = $table;
        }
        foreach ($columns as $column) {
            $table = $column['TABLE_NAME'];
            if (!str_starts_with($table, $prefix) || $table === $prefix . 'plugin_example'
                || in_array(substr($table, strlen($prefix)), self::LATER_TABLES, true)) continue;
            $name = substr($table, strlen($prefix)) . '.' . $column['COLUMN_NAME'];
            if (in_array($name, $extensions, true)) continue;
            ++$columnCount;
            if (preg_match('/\p{Han}/u', $column['COLUMN_COMMENT']) !== 1) $missing[] = $name;
        }
        self::assertSame(63, $tableCount);
        self::assertSame(581, $columnCount);
        self::assertSame([], $missing, '核心表或字段缺少中文注释');
    }

    /** @return array<string, mixed> 不包含注释和迁移新增记录的结构、业务数据基线。 */
    private function snapshot(PDO $pdo): array
    {
        $columns = $pdo->query('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as &$column) unset($column['COLUMN_COMMENT']);
        unset($column);
        $columns = $this->historicalRows($columns);
        $data = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table === 'orm_migration_versions' || in_array(substr($table, 4), self::LATER_TABLES, true)) continue;
            $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
            // 后续消息通知迁移新增的是分类元数据，不属于历史业务数据。
            if ($table === 'orm_system_setting_categories') {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['code'] !== 'notifications'));
            }
            $rows = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($rows);
            $data[$table] = hash('sha256', implode("\n", $rows));
        }
        return [
            'columns' => $columns,
            'indexes' => $this->historicalRows($pdo->query('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE, IS_VISIBLE, EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC)),
            'keys' => $this->historicalRows($pdo->query('SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC)),
            'references' => $this->historicalRows($pdo->query('SELECT * FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC)),
            'tables' => $this->historicalRows($pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC)),
            'data' => $data,
        ];
    }
    /** @param list<array<string,mixed>> $rows 结构资料 @return list<array<string,mixed>> 历史表结构 */
    private function historicalRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if ($row['TABLE_NAME'] === 'orm_system_setting_categories') unset($row['AUTO_INCREMENT']);
        }
        unset($row);
        return array_values(array_filter($rows, static fn (array $row): bool => !in_array(substr($row['TABLE_NAME'], 4), self::LATER_TABLES, true)));
    }
}
