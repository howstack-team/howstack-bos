<?php

declare(strict_types=1);

namespace tests\support;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionProperty;
use ReflectionMethod;
use support\Db;
use support\Model;

/** 每个测试使用真实迁移的独立 SQLite 数据库，不访问安装数据库。 */
trait IsolatedBusinessDatabase
{
    private mixed $previousCapsule;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private string $databasePath;
    private Capsule $testCapsule;
    private ?\PDO $mysqlServer = null;
    protected ?int $initialMigrationVersion = null;

    protected function setUp(): void
    {
        class_exists(Db::class);
        class_exists(Model::class);
        $this->previousCapsule = (new ReflectionProperty(Capsule::class, 'instance'))->getValue();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $mysql = getenv('HOWSTACK_MYSQL_TEST') === '1';
        if ($mysql) {
            \Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__, 2))->safeLoad();
            $connection = require dirname(__DIR__, 2) . '/config/database.php';
            $connection = $connection['connections']['mysql'];
            $this->databasePath = 'howstack_orm_test_' . bin2hex(random_bytes(8));
            $this->mysqlServer = new \PDO('mysql:host=' . $connection['host'] . ';port=' . $connection['port'], $connection['username'], $connection['password']);
            $this->mysqlServer->exec('CREATE DATABASE `' . $this->databasePath . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $connection['database'] = $this->databasePath;
            $connection['prefix'] = 'orm_';
            unset($connection['pool']);
            $configuration = MigrationDatabaseConfiguration::fromArray($connection);
        } else {
            $this->databasePath = tempnam(sys_get_temp_dir(), 'howstack-orm-');
            $connection = ['driver' => 'sqlite', 'database' => $this->databasePath, 'prefix' => 'orm_'];
            $configuration = new MigrationDatabaseConfiguration('sqlite', $this->databasePath, 'orm_');
        }
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        try {
            if ($this->initialMigrationVersion === null) {
                $runner->migrate($configuration);
            } else {
                [$manager] = (new ReflectionMethod($runner, 'manager'))->invoke($runner, $configuration);
                $manager->migrate('runtime', $this->initialMigrationVersion);
            }
        } catch (\Throwable $error) {
            if ($this->mysqlServer !== null) $this->mysqlServer->exec('DROP DATABASE `' . $this->databasePath . '`');
            else unlink($this->databasePath);
            throw $error;
        }
        $this->testCapsule = new Capsule();
        $this->testCapsule->addConnection($connection);
        $this->testCapsule->setAsGlobal();
        $this->testCapsule->bootEloquent();
        Model::unsetEventDispatcher();
        $now = '2026-10-01 08:30:00';
        $this->row('admin_users', ['id' => 1, 'username' => 'fixture-admin', 'nickname' => '测试管理员', 'password_hash' => 'hash', 'created_at' => $now, 'updated_at' => $now]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->testCapsule)) return;
        $this->testCapsule->getConnection()->disconnect();
        (new ReflectionProperty(Capsule::class, 'instance'))->setValue(null, $this->previousCapsule);
        Model::setConnectionResolver($this->previousResolver);
        if ($this->previousDispatcher !== null) Model::setEventDispatcher($this->previousDispatcher);
        else Model::unsetEventDispatcher();
        if ($this->mysqlServer !== null) $this->mysqlServer->exec('DROP DATABASE `' . $this->databasePath . '`');
        else unlink($this->databasePath);
    }

    /** @param array<string, int|string|null> $data 受控测试数据 */
    private function row(string $table, array $data): int
    {
        return (int) Db::table($table)->insertGetId($data);
    }

    private function member(string $mobile = '13800138001'): int
    {
        $now = '2026-10-01 08:30:00';
        return $this->row('members', ['mobile' => $mobile, 'nickname' => '测试会员', 'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'created_at' => $now, 'updated_at' => $now]);
    }

    private function order(int $memberId, string $number = 'ORDER-TEST'): int
    {
        $now = '2026-10-01 08:30:00';
        $id = $this->row('orders', ['order_no' => $number, 'member_id' => $memberId, 'member_mobile_snapshot' => '13800138001',
            'member_nickname_snapshot' => '测试会员', 'order_type' => 'purchase', 'status' => 'pending_payment',
            'payment_status' => 'unpaid', 'currency' => 'CNY', 'subtotal_minor' => 1500, 'discount_minor' => 300,
            'payable_minor' => 1200, 'paid_minor' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $item = $this->row('order_items', ['order_id' => $id, 'line_no' => 1, 'product_type' => 'cloud_server', 'product_type_version' => '1',
            'sales_version' => '1', 'product_name' => '云服务器', 'configuration_snapshot' => '{"secret":"must-not-leak"}',
            'quantity' => 2, 'unit_price_minor' => 750, 'total_minor' => 1500, 'created_at' => $now]);
        foreach ([1, 2] as $index) $this->row('order_item_units', ['order_item_id' => $item, 'unit_index' => $index, 'allocated_minor' => 600, 'created_at' => $now]);
        return $id;
    }

    private function recharge(int $memberId, int $amount = 1500): int
    {
        $now = '2026-10-01 08:30:00';
        $id = $this->row('recharges', ['recharge_no' => 'RECHARGE-TEST', 'member_id' => $memberId, 'amount_minor' => $amount,
            'currency' => 'CNY', 'channel' => 'wechat', 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now]);
        $this->row('payment_transactions', ['payment_no' => 'PAYMENT-RECHARGE', 'member_id' => $memberId, 'purpose' => 'recharge',
            'purpose_id' => $id, 'channel' => 'wechat', 'channel_trade_no' => 'CHANNEL-TEST', 'amount_minor' => $amount,
            'currency' => 'CNY', 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now]);
        return $id;
    }
}
