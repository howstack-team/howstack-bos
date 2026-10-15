<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\website\infrastructure\DatabaseWebsiteRepository;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 迁移旧资料及主题快照，验证配置不丢失且只读角色不获得写权限。 */
final class WebsiteSettingsMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261010000200;
        $this->initializeDatabase();
    }

    public function testMigratesCompanySeoAndRoleScopesWithoutOverwritingThemeCustomization(): void
    {
        $repository = new DatabaseWebsiteRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $website = (array) Db::table('website_settings')->where('id', 1)->first(); $website['settings'] = json_decode($website['settings'], true);
        foreach (['company', 'seo_title', 'seo_keywords', 'seo_description'] as $field) unset($website['settings'][$field]);
        Db::table('website_settings')->where('id', 1)->update(['settings' => json_encode($website['settings'], JSON_THROW_ON_ERROR)]);
        $company = ['introduction' => '已有公司资料', 'contact_phone' => '4000000000', 'contact_email' => 'info@example.test', 'address' => '杭州', 'image_id' => ''];
        $theme = (array) Db::table('website_theme_settings')->where('theme_id', 'default')->first(); $theme['settings'] = json_decode($theme['settings'], true);
        $theme['settings']['pages']['company'] = $company;
        $theme['settings']['global']['hero_title'] = '保留首页定制';
        Db::table('website_theme_settings')->where('theme_id', 'default')->update(['settings' => json_encode($theme['settings'], JSON_THROW_ON_ERROR)]);
        Db::table('system_settings')->where('setting_key', 'site.keywords')->update(['setting_value' => '旧关键词']);
        Db::table('system_settings')->where('setting_key', 'site.description')->update(['setting_value' => '旧描述']);
        $role = Db::table('admin_roles')->insertGetId(['name' => '官网只读', 'code' => 'website-reader', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        Db::table('admin_role_permissions')->insert(['role_id' => $role, 'permission_code' => 'admin.page.website.view']);
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261011000100, 20261011000200, 20261011000300, 20261011000400, 20261011000500, 20261011000600, 20261011000700, 20261011000800, 20261011000900, 20261011001000], $runner->migrate($configuration)->executed);
        $after = $repository->website();
        self::assertEquals($company, $after['settings']['company']);
        self::assertSame('旧关键词', $repository->pageSeo('default')[0]['keywords']);
        self::assertSame('旧描述', $repository->pageSeo('default')[0]['description']);
        self::assertSame($website['settings']['navigation'], $after['settings']['navigation']);
        self::assertSame('保留首页定制', $repository->snapshot()['theme']['settings']['global']['hero_title']);
        self::assertSame(['show_contact' => true], $repository->snapshot()['theme']['settings']['pages']['company']);
        self::assertSame('1.1.1', $after['active_theme_version']);
        self::assertSame(0, Db::table('system_settings')->whereIn('setting_key', ['site.keywords', 'site.description'])->count());
        self::assertSame(['admin.page.website.view', 'admin.website.base.view', 'admin.website.navigation.view', 'admin.website.seo.view'], Db::table('admin_role_permissions')->where('role_id', $role)->orderBy('permission_code')->pluck('permission_code')->all());
        self::assertSame([], $runner->migrate($configuration)->executed);
        self::assertSame($after, $repository->website());
    }
}
