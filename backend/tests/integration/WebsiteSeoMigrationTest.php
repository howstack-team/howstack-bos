<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\website\infrastructure\DatabaseWebsiteRepository;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实前向迁移保留原默认值，授权继承不扩张读写能力，失败重试不覆盖SEO。 */
final class WebsiteSeoMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261011000200;
        $this->initializeDatabase();
    }

    public function testUpgradePreservesSettingsAndRoleScopesAndRetry(): void
    {
        $repository = new DatabaseWebsiteRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $before = $repository->website();
        $now = date('Y-m-d H:i:s');
        $read = $this->row('admin_roles', ['name' => '官网只读', 'code' => 'seo-reader', 'created_at' => $now, 'updated_at' => $now]);
        $write = $this->row('admin_roles', ['name' => '官网编辑', 'code' => 'seo-writer', 'created_at' => $now, 'updated_at' => $now]);
        Db::table('admin_role_permissions')->insert([
            ['role_id' => $read, 'permission_code' => 'admin.website.base.view'],
            ['role_id' => $write, 'permission_code' => 'admin.website.base.view'],
            ['role_id' => $write, 'permission_code' => 'admin.website.base.update'],
        ]);
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261011000300, 20261011000400, 20261011000500,20261011000600, 20261011000700], $runner->migrate($configuration)->executed);
        self::assertSame($before['revision'], $repository->website()['revision']);
        self::assertArrayNotHasKey('seo_title', $repository->website()['settings']);
        self::assertSame(['admin.website.base.view', 'admin.website.seo.view'], Db::table('admin_role_permissions')->where('role_id', $read)->orderBy('permission_code')->pluck('permission_code')->all());
        self::assertSame(['admin.website.base.update', 'admin.website.base.view', 'admin.website.seo.update', 'admin.website.seo.view'], Db::table('admin_role_permissions')->where('role_id', $write)->orderBy('permission_code')->pluck('permission_code')->all());
        self::assertSame('', $repository->pageSeo('default')[0]['title']);
        $repository->savePageSeo($before['revision'], 'core', 'home', ['title' => '已保存标题', 'description' => '', 'keywords' => '', 'indexable' => null]);
        self::assertSame([], $runner->migrate($configuration)->executed);
        self::assertSame('已保存标题', $repository->pageSeo('default')[0]['title']);
        // 模拟DDL已成功但迁移记账前失败，重跑up仍不能覆盖配置或重复授权。
        $migration = new \CreateWebsitePageSeo('runtime', 20261011000300);
        [$manager] = (new \ReflectionMethod($runner, 'manager'))->invoke($runner, $configuration);
        $migration->setAdapter($manager->getEnvironment('runtime')->getAdapter());
        $migration->up();
        self::assertSame('已保存标题', $repository->pageSeo('default')[0]['title']);
        self::assertSame(6, Db::table('admin_role_permissions')->whereIn('role_id', [$read, $write])->count());
    }
}
