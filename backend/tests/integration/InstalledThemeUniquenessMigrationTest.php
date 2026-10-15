<?php

declare(strict_types=1);
namespace tests\integration;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** MySQL与SQLite真实迁移：保留启用记录、删除多版本、唯一约束及重试。 */
final class InstalledThemeUniquenessMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }
    protected function setUp(): void { $this->initialMigrationVersion = 20261011001100; $this->initializeDatabase(); }
    public function testKeepsActiveRecordConfigurationAndUniqueIdentity(): void
    {
        $now = date('Y-m-d H:i:s'); $ids = [];
        foreach (['1.0.0', '2.0.0'] as $version) {
            $id = Db::table('site_theme_releases')->insertGetId(['theme_id' => 'example', 'name' => '示例', 'version' => $version, 'digest' => hash('sha256', $version), 'components' => '["website"]', 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            Db::table('site_theme_configurations')->insert(['release_id' => $id, 'component' => 'website', 'settings' => json_encode(['title' => '配置-' . $version]), 'revision' => 8, 'created_at' => $now, 'updated_at' => $now]); $ids[] = $id;
        }
        Db::table('site_theme_bindings')->where('id', 1)->update(['release_id' => $ids[0]]);
        Db::table('website_settings')->where('id', 1)->update(['active_release_id' => $ids[0]]);
        $before = Db::table('site_theme_configurations')->where('release_id', $ids[0])->first();
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        self::assertSame([20261011001200, 20261011001300], $runner->migrate($configuration)->executed);
        self::assertSame(1, Db::table('site_theme_releases')->where('theme_id', 'example')->count());
        self::assertSame($ids[0], (int) Db::table('site_theme_bindings')->value('release_id'));
        self::assertSame($ids[0], (int) Db::table('website_settings')->value('active_release_id'));
        self::assertEquals($before, Db::table('site_theme_configurations')->where('release_id', $ids[0])->first());
        self::assertSame(0, Db::table('site_theme_configurations')->where('release_id', $ids[1])->count());
        try {
            Db::table('site_theme_releases')->insert(['theme_id' => 'example', 'name' => '重复', 'version' => '3.0.0', 'digest' => str_repeat('d', 64), 'components' => '[]', 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now]); self::fail('不同版本也不能重复主题ID');
        } catch (\Illuminate\Database\QueryException $error) { self::assertContains((string) $error->getCode(), ['23000', '19']); }
        self::assertSame([], $runner->migrate($configuration)->executed);
        if (Db::connection()->getDriverName() === 'mysql') {
            $prefix = Db::connection()->getTablePrefix();
            $index = Db::selectOne('SELECT NON_UNIQUE AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$prefix . 'site_theme_releases', 'idx_site_theme_identity']);
            self::assertSame(0, (int) $index->n);
            foreach (Db::select('SHOW FULL COLUMNS FROM ' . $prefix . 'site_theme_releases') as $column) self::assertMatchesRegularExpression('/\\p{Han}/u', $column->Comment);
        }
    }
}
