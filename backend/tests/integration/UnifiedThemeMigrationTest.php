<?php

declare(strict_types=1);
namespace tests\integration;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\website\infrastructure\{LegacyFileThemeRegistry, DatabaseWebsiteRepository};
use app\modules\file\infrastructure\DatabaseFileRegistry;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
final class UnifiedThemeMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }
    protected function setUp(): void { $this->initialMigrationVersion = 20261011001000; $this->initializeDatabase(); }
    public function testLegacySnapshotUpgradePreservesConfigurationAndPublicSettings(): void
    {
        $root = sys_get_temp_dir() . '/legacy-theme-' . bin2hex(random_bytes(6)); $directory = $root . '/default'; mkdir($directory, 0750, true);
        $schema = json_decode(file_get_contents(base_path('themes/default/website/settings.schema.json')), true); $defaults = $schema['default'];
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('themes/default/website'), \FilesystemIterator::SKIP_DOTS)) as $file) {
                $name = substr($file->getPathname(), strlen(base_path('themes/default/website')) + 1); if (str_ends_with($name, '.json')) continue;
                $target = $directory . '/' . (str_starts_with($name, 'assets/') ? $name : 'templates/' . $name); if (!is_dir(dirname($target))) mkdir(dirname($target), 0750, true); copy($file->getPathname(), $target);
            }
            copy(base_path('themes/default/cover.svg'), $directory . '/cover.svg');
            file_put_contents($directory . '/theme.json', json_encode(['id' => 'default', 'name' => '旧官网', 'version' => '1.8.0', 'theme_api' => '1', 'requires' => ['core' => '>=0.1.0 <1.0.0'], 'preview' => 'cover.svg', 'templates' => ['home' => 'index.html.twig'], 'pages' => ['company' => ['path' => '/company', 'title' => '公司介绍', 'template' => 'pages/company.html.twig', 'settings_schema' => 'company.json']], 'settings_schema' => 'settings.json', 'settings_defaults' => 'defaults.json']));
            file_put_contents($directory . '/settings.json', json_encode($this->withoutDefaults($schema['properties']['global']))); file_put_contents($directory . '/company.json', json_encode($this->withoutDefaults($schema['properties']['pages']['properties']['company']))); file_put_contents($directory . '/defaults.json', json_encode($defaults));
            $registry = new LegacyFileThemeRegistry($root, runtime_path('website-themes'), public_path('theme-assets'), '0.1.0'); $package = $registry->read('default'); $registry->publish($package);
            $now = date('Y-m-d H:i:s'); $id = Db::table('site_theme_releases')->insertGetId(['theme_id' => 'default', 'name' => '旧官网', 'version' => '1.8.0', 'digest' => $package['digest'], 'components' => '["website"]', 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $defaults['global']['hero_title'] = '客户原有标题';
            Db::table('site_theme_configurations')->insert(['release_id' => $id, 'component' => 'website', 'settings' => json_encode($defaults), 'revision' => 7, 'created_at' => $now, 'updated_at' => $now]);
            Db::table('website_settings')->where('id', 1)->update(['active_release_id' => $id]); $before = json_decode(Db::table('website_settings')->value('settings'), true);
            // 模拟迁移中断：版本已登记，组件配置尚未写入；重试必须复用此版本。
            $current = new \app\modules\website\infrastructure\FileThemeRegistry(base_path('resources/migration-themes/directory-layout'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0', true);
            $default = $current->read('default');
            (new \app\modules\website\infrastructure\UpgradeInstalledThemePackage())->convert($package, $root . '/converted', '1.8.1', $default);
            $converted = (new \app\modules\website\infrastructure\FileThemeRegistry($root . '/converted', runtime_path('website-themes'), public_path('theme-assets'), '0.1.0', true))->read('default');
            $current->publish($converted);
            Db::table('site_theme_releases')->insert(['theme_id' => 'default', 'name' => '旧官网', 'version' => '1.8.1', 'digest' => $converted['digest'], 'components' => '["website","responsive"]', 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $defaultId = Db::table('site_theme_releases')->where('theme_id', 'default')->where('version', '2.0.0')->value('id');
            if ($defaultId) Db::table('site_theme_configurations')->where('release_id', $defaultId)->where('component', 'responsive')->delete();
            $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector()); $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
            self::assertSame([20261011001100, 20261011001200, 20261011001300, 20261011001400, 20261012000100], $runner->migrate($configuration)->executed);
            self::assertFalse(Db::connection()->getSchemaBuilder()->hasTable('console_theme_bindings'));
            $after = (new DatabaseWebsiteRepository(new DatabaseFileRegistry()))->snapshot();
            self::assertSame($before, $after['website']['settings']); self::assertSame('客户原有标题', $after['theme']['settings']['global']['hero_title']); self::assertSame(7, $after['theme']['revision']);
            self::assertSame(1, Db::table('site_theme_releases')->where('theme_id', 'default')->count()); self::assertFileExists($package['directory'] . '/templates/index.html.twig');
            self::assertSame(0, Db::table('site_theme_releases')->where('theme_id', 'default')->where('version', '1.8.2')->count());
            self::assertSame(1, Db::table('site_theme_configurations')->where('release_id', $after['website']['active_release_id'])->where('component', 'pc')->count());
            self::assertSame([], $runner->migrate($configuration)->executed);
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); rmdir($root);
        }
    }
    private function withoutDefaults(array $schema): array
    {
        unset($schema['default']); foreach ($schema['properties'] ?? [] as $key => $child) $schema['properties'][$key] = $this->withoutDefaults($child);
        if (isset($schema['items'])) $schema['items'] = $this->withoutDefaults($schema['items']); return $schema;
    }
}
