<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 元数据规范化不能隐式核准代码变更或改写插件启停状态。 */
final class PluginMetadataMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testNormalizationIsAuditedOnceAndPreservesStateAndVersion(): void
    {
        $original = str_repeat('a', 64);
        $runtime = str_repeat('b', 64);
        Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->update([
            'package_hash' => $original,
            'state' => 'disabled',
        ]);
        $row = (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first();
        $migration = $this->migration();
        $normalize = new ReflectionMethod($migration, 'normalize');
        $events = Db::table('business_plugin_events')->count();
        $metadataEvents = Db::table('business_plugin_events')->where('action', 'metadata_normalize')->count();
        $normalize->invoke($migration, $row, $original, $runtime);
        $normalize->invoke($migration, $row, $original, $runtime);
        $after = (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first();
        self::assertSame($runtime, $after['package_hash']);
        self::assertSame((int) $row['revision'] + 1, (int) $after['revision']);
        self::assertSame('disabled', $after['state']);
        self::assertSame($row['version'], $after['version']);
        self::assertSame($events + 1, Db::table('business_plugin_events')->count());
        self::assertSame($metadataEvents + 1, Db::table('business_plugin_events')->where('action', 'metadata_normalize')->count());
    }

    public function testChangedCodeAndStaleRevisionAreNeverApproved(): void
    {
        $migration = $this->migration();
        $normalize = new ReflectionMethod($migration, 'normalize');
        $row = (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first();
        $events = Db::table('business_plugin_events')->count();
        $normalize->invoke($migration, $row, str_repeat('c', 64), str_repeat('d', 64));
        $normalize->invoke($migration, [...$row, 'revision' => (int) $row['revision'] - 1], $row['package_hash'], str_repeat('e', 64));
        self::assertSame($row, (array) Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->first());
        self::assertSame($events, Db::table('business_plugin_events')->count());
    }

    /** @return \Phinx\Migration\MigrationInterface 已绑定隔离测试数据库的迁移 */
    private function migration(): \Phinx\Migration\MigrationInterface
    {
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        [$manager] = (new ReflectionMethod($runner, 'manager'))->invoke($runner, $configuration);
        $migration = $manager->getMigrations('runtime')[20261006000300];
        $migration->setAdapter($manager->getEnvironment('runtime')->getAdapter());
        return $migration;
    }
}
