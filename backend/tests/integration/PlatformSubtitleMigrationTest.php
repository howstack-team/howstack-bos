<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\infrastructure\DatabaseSystemSettingsRepository;
use app\modules\website\infrastructure\DatabaseWebsiteRepository;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 前向迁移清除副标题，保留其他设置和定制，并更新当前默认主题快照。 */
final class PlatformSubtitleMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261011000100;
        $this->initializeDatabase();
    }

    public function testRemovalPreservesOtherSettingsAndThemeCustomization(): void
    {
        Db::table('system_settings')->where('setting_key', 'site.subtitle')->update(['setting_value' => '旧平台副标题']);
        $remaining = Db::table('system_settings')->where('setting_key', '<>', 'site.subtitle')->orderBy('setting_key')->pluck('setting_value', 'setting_key')->all();
        $repository = new DatabaseWebsiteRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $website = $repository->website();
        $theme = $repository->theme('default');
        $theme['settings']['global']['hero_title'] = '已有主题定制';
        Db::table('website_theme_settings')->where('theme_id', 'default')->update(['settings' => json_encode($theme['settings'], JSON_THROW_ON_ERROR)]);
        $theme = $repository->theme('default');
        // 模拟升级前已发布的旧包指针，迁移应切换包而非覆盖用户配置。
        Db::table('website_themes')->where('theme_id', 'default')->update(['version' => '1.1.0', 'digest' => str_repeat('a', 64)]);
        Db::table('website_settings')->where('id', 1)->update(['active_theme_version' => '1.1.0', 'active_theme_digest' => str_repeat('a', 64)]);
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261011000200, 20261011000300], $runner->migrate($configuration)->executed);
        self::assertSame(0, Db::table('system_settings')->where('setting_key', 'site.subtitle')->count());
        self::assertSame($remaining, Db::table('system_settings')->orderBy('setting_key')->pluck('setting_value', 'setting_key')->all());
        self::assertArrayNotHasKey('site_subtitle', (new GetSystemSettingsService(new DatabaseSystemSettingsRepository()))->base());
        self::assertSame($theme, $repository->theme('default'));
        $after = $repository->website();
        self::assertSame($website['settings'], $after['settings']);
        self::assertSame('1.1.1', $after['active_theme_version']);
        self::assertNotSame(str_repeat('a', 64), $after['active_theme_digest']);
        self::assertSame($website['revision'] + 1, $after['revision']);
        self::assertSame([], $runner->migrate($configuration)->executed);
        self::assertSame($after, $repository->website());
    }
}
