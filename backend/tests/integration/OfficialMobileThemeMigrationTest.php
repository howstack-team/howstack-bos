<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\website\infrastructure\FileThemeRegistry;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 新安装重放历史包后必须得到实际移动入口，更新不丢失官网配置。 */
final class OfficialMobileThemeMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261011001300;
        $this->initializeDatabase();
    }

    public function testFreshInstallRegistersBuiltMobileAndPreservesSettings(): void
    {
        $before = Db::table('site_theme_bindings')->where('id', 1)->first();
        $theme = Db::table('site_theme_releases')->where('theme_id', 'default')->first();
        self::assertNotContains('mobile', json_decode($theme->components, true));
        $configuration = Db::table('site_theme_configurations')->where('release_id', $theme->id)->where('component', 'website')->first();
        $settings = json_decode($configuration->settings, true);
        $settings['global']['hero_title'] = '保留客户自定义标题';
        Db::table('site_theme_configurations')->where('id', $configuration->id)->update(['settings' => json_encode($settings), 'revision' => 7]);

        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $database = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261011001400, 20261012000100], $runner->migrate($database)->executed);
        $record = Db::table('site_theme_releases')->where('theme_id', 'default')->first();
        self::assertSame($theme->id, $record->id);
        self::assertSame(['website', 'pc', 'mobile'], json_decode($record->components, true));
        $registry = new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0');
        self::assertSame($registry->read('default')['digest'], $record->digest);
        $package = $registry->pinned('default', $record->version, $record->digest);
        self::assertFileExists($package['directory'] . '/console/mobile/index.html');
        self::assertFileExists($package['directory'] . '/console/mobile/licenses.json');
        self::assertSame(1, Db::table('site_theme_configurations')->where('release_id', $theme->id)->where('component', 'mobile')->count());
        $saved = Db::table('site_theme_configurations')->where('id', $configuration->id)->first();
        self::assertSame($settings, json_decode($saved->settings, true));
        self::assertSame(7, (int) $saved->revision);
        $after = Db::table('site_theme_bindings')->where('id', 1)->first();
        self::assertSame($before->release_id, $after->release_id);
        self::assertSame($before->default_release_id, $after->default_release_id);
        self::assertFalse((bool) $after->mobile_enabled);
        self::assertSame([], $runner->migrate($database)->executed);
    }
}
