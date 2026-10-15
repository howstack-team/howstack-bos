<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 验证真实安装初始化的摘要、审计、幂等与已有停用状态保护；不连接外部服务。 */
final class BuiltinChannelMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testFreshInstallationRegistersOnlyBundledChannelsAndLeavesBusinessDisabled(): void
    {
        $packages = new LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        $installed = Db::table('business_plugin_installations')->get()->keyBy('plugin_id');
        self::assertCount(22, $installed);
        self::assertFalse($installed->has('magic_cloud'));
        self::assertSame(0, Db::table('channel_connections')->count());
        self::assertSame(7, Db::table('channel_category_settings')->count());
        self::assertSame(0, Db::table('channel_category_settings')->whereNotNull('default_channel_id')->count());
        foreach ($installed as $id => $row) {
            $package = $packages->packages()[$id];
            self::assertSame($package['category'], $row->category);
            self::assertSame($package['version'], $row->version);
            self::assertSame($package['package_hash'], $row->package_hash);
            self::assertSame('enabled', $row->state);
            self::assertSame(2, Db::table('business_plugin_events')->where('plugin_id', $id)->whereIn('action', ['install', 'enable'])->count());
        }
    }

    public function testRerunPreservesExistingStatesAndDoesNotRegisterThirdPartyPackages(): void
    {
        foreach (['disabled', 'draining', 'uninstalled', 'installed', 'enabled'] as $index => $state) {
            $id = ['aliyun_sms', 'tencent_sms', 'submail_sms', 'smsbao', 'smtp'][$index];
            Db::table('business_plugin_installations')->where('plugin_id', $id)->update([
                'state' => $state, 'revision' => 9, 'package_hash' => str_repeat('a', 64),
            ]);
        }
        $before = Db::table('business_plugin_installations')->orderBy('id')->get()->toArray();
        $events = Db::table('business_plugin_events')->count();
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        self::assertEquals($before, Db::table('business_plugin_installations')->orderBy('id')->get()->toArray());
        self::assertSame($events, Db::table('business_plugin_events')->count());
        self::assertSame(0, Db::table('channel_connections')->count());
    }

    /** @return \Phinx\Migration\MigrationInterface 已绑定隔离测试数据库的迁移 */
    private function migration(): \Phinx\Migration\MigrationInterface
    {
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        [$manager] = (new ReflectionMethod($runner, 'manager'))->invoke($runner, $configuration);
        $migration = $manager->getMigrations('runtime')[20261007000100];
        $migration->setAdapter($manager->getEnvironment('runtime')->getAdapter());
        return $migration;
    }
}
