<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 入口布局变更只能核准确定的包指纹，不改变连接配置和历史业务。 */
final class PluginEntryLayoutMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testExactPackageTransitionPreservesStateAndConnectionConfiguration(): void
    {
        $migration = $this->migration();
        [, , $oldHash, $newHash] = (new ReflectionClass($migration))->getConstant('PACKAGES')['smtp'];
        Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->update(['package_hash' => $oldHash, 'state' => 'disabled']);
        $platform = ['id' => 'smtp', 'version' => '1.0.0', 'package_hash' => $oldHash, 'configuration_fields' => []];
        $id = $this->channel($platform);
        $archived = $this->channel($platform, '2026-10-01 00:00:00');
        $connection = (array) Db::table('channel_connections')->where('id', $id)->first();
        $record = (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first();
        $events = Db::table('business_plugin_events')->count();
        $migration->up();
        $migration->up();
        $after = (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first();
        self::assertSame($newHash, $after['package_hash']);
        self::assertSame('disabled', $after['state']);
        self::assertSame($record['version'], $after['version']);
        self::assertSame((int) $record['revision'] + 1, (int) $after['revision']);
        self::assertSame($events + 1, Db::table('business_plugin_events')->count());
        $platform['package_hash'] = $newHash;
        $current = (array) Db::table('channel_connections')->where('id', $id)->first();
        self::assertEquals($platform, json_decode($current['platform_snapshot'], true, flags: JSON_THROW_ON_ERROR));
        unset($connection['platform_snapshot'], $current['platform_snapshot']);
        self::assertSame($connection, $current);
        self::assertSame($oldHash, json_decode(Db::table('channel_connections')->where('id', $archived)->value('platform_snapshot'), true)['package_hash']);
    }

    public function testDifferentApprovedHashIsNotSilentlyReplaced(): void
    {
        Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->update(['package_hash' => str_repeat('a', 64)]);
        $before = Db::table('business_plugin_installations')->orderBy('id')->get()->toArray();
        $events = Db::table('business_plugin_events')->count();
        $this->migration()->up();
        self::assertEquals($before, Db::table('business_plugin_installations')->orderBy('id')->get()->toArray());
        self::assertSame($events, Db::table('business_plugin_events')->count());
    }

    public function testProviderFingerprintPreservesTestAndCredentials(): void
    {
        $migration = $this->migration();
        [$category, $version, $oldHash, $newHash] = (new ReflectionClass($migration))->getConstant('PACKAGES')['magic_cloud'];
        $now = '2026-10-01 08:00:00';
        Db::table('business_plugin_installations')->insert(['plugin_id' => 'magic_cloud', 'category' => $category, 'version' => $version, 'package_hash' => $oldHash, 'state' => 'draining', 'revision' => 7, 'created_at' => $now, 'updated_at' => $now]);
        $capabilities = ['_adapter' => ['id' => 'magic_cloud', 'manifest_hash' => $oldHash], 'catalog' => true];
        $id = Db::table('provider_connections')->insertGetId(['name' => '测试连接', 'adapter_id' => 'magic_cloud', 'status' => 0, 'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR), 'credentials_encrypted' => 'opaque-fixture', 'tested_at' => $now, 'revision' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $before = (array) Db::table('provider_connections')->where('id', $id)->first();
        $migration->up();
        $capabilities['_adapter']['manifest_hash'] = $newHash;
        $before['capabilities'] = json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame($before, (array) Db::table('provider_connections')->where('id', $id)->first());
        self::assertSame('draining', Db::table('business_plugin_installations')->where('plugin_id', 'magic_cloud')->value('state'));
    }

    public function testAuditFailureRollsBackApprovalAndConnection(): void
    {
        $migration = $this->migration();
        [, , $oldHash] = (new ReflectionClass($migration))->getConstant('PACKAGES')['smtp'];
        Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->update(['package_hash' => $oldHash]);
        $id = $this->channel(['id' => 'smtp']);
        Db::table('channel_connections')->where('id', $id)->update(['platform_snapshot' => json_encode(['id' => 'smtp', 'package_hash' => $oldHash], JSON_THROW_ON_ERROR)]);
        $before = (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first();
        $connection = (array) Db::table('channel_connections')->where('id', $id)->first();
        $events = Db::table('business_plugin_events')->count();
        // 审计写入失败时，安装登记和此前的连接指纹更新都必须回滚。
        $adapter = $migration->getAdapter();
        $quote = fn (string $table): string => $adapter->quoteTableName(($adapter->getOption('table_prefix') ?? '') . $table);
        $pdo = $adapter->getConnection();
        $trigger = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? " WHEN NEW.action='entry_layout' BEGIN SELECT RAISE(ABORT,'fixture failure'); END"
            : " FOR EACH ROW BEGIN IF NEW.action='entry_layout' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'; END IF; END";
        $pdo->exec('CREATE TRIGGER block_entry_event BEFORE INSERT ON ' . $quote('business_plugin_events') . $trigger);
        try {
            $migration->up();
            self::fail('审计写入失败应回滚整笔迁移');
        } catch (\PDOException) {
            self::assertSame($before, (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first());
            self::assertSame($connection, (array) Db::table('channel_connections')->where('id', $id)->first());
            self::assertSame($events, Db::table('business_plugin_events')->count());
        }
    }

    /** @param array<string, mixed> $snapshot 无秘密平台声明 @return int 测试连接主键 */
    private function channel(array $snapshot, ?string $deletedAt = null): int
    {
        $now = '2026-10-01 08:00:00';
        return Db::table('channel_connections')->insertGetId(['category' => 'mail', 'name' => '测试邮件', 'plugin_id' => 'smtp', 'platform_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'secret_keys' => '[]', 'configuration_encrypted' => 'opaque-fixture', 'status' => 1, 'revision' => 3, 'deleted_at' => $deletedAt, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @return \Phinx\Migration\MigrationInterface 绑定隔离数据库的前向迁移 */
    private function migration(): \Phinx\Migration\MigrationInterface
    {
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        [$manager] = (new ReflectionMethod($runner, 'manager'))->invoke($runner, MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig()));
        $migration = $manager->getMigrations('runtime')[20261012000200];
        $migration->setAdapter($manager->getEnvironment('runtime')->getAdapter());
        return $migration;
    }
}
