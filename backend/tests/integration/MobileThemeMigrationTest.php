<?php

declare(strict_types=1);
namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\website\infrastructure\{FileThemeRegistry, HistoricalThemeLayout};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 重放历史目录契约后升级，验证ID、配置修订、入口及默认开关。 */
final class MobileThemeMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }
    protected function setUp(): void { $this->initialMigrationVersion = 20261011001200; $this->initializeDatabase(); }

    #[DataProvider('layouts')]
    public function testMigrationPreservesCurrentThemeAndSettings(array $types, bool $mobile): void
    {
        $root = sys_get_temp_dir() . '/legacy-device-' . bin2hex(random_bytes(6));
        mkdir($root . '/example', 0750, true);
        try {
            file_put_contents($root . '/example/cover.svg', '<svg/>');
            file_put_contents($root . '/example/theme.json', '{"id":"example","name":"旧设备主题","version":"1.0.0","theme_api":"1","requires":{"core":">=0.1.0 <1.0.0"},"cover":"cover.svg"}');
            foreach ($types as $type) {
                mkdir($root . '/example/console/' . $type, 0750, true);
                file_put_contents($root . '/example/console/' . $type . '/index.html', '<html><head></head><body>' . $type . '</body></html>');
                file_put_contents($root . '/example/console/' . $type . '/settings.schema.json', '{"type":"object","additionalProperties":false,"properties":{"greeting":{"type":"string","maxLength":80,"default":"默认"}},"required":["greeting"]}');
            }
            $registry = new FileThemeRegistry($root, runtime_path('website-themes'), public_path('theme-assets'), '0.1.0', true);
            $package = $registry->read('example'); $registry->publish($package); $now = date('Y-m-d H:i:s');
            $id = Db::table('site_theme_releases')->insertGetId(['theme_id' => 'example', 'name' => '旧设备主题', 'version' => '1.0.0', 'digest' => $package['digest'], 'components' => json_encode($types), 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($types as $type) Db::table('site_theme_configurations')->insert(['release_id' => $id, 'component' => $type, 'settings' => json_encode(['greeting' => $type . '客户配置']), 'revision' => 9, 'created_at' => $now, 'updated_at' => $now]);
            Db::table('site_theme_bindings')->where('id', 1)->update(['release_id' => $id]);
            $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
            $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
            self::assertSame([20261011001300, 20261011001400], $runner->migrate($configuration)->executed);
            self::assertFalse(HistoricalThemeLayout::active());
            $record = Db::table('site_theme_releases')->where('id', $id)->first();
            self::assertSame('example', $record->theme_id);
            self::assertNotContains('responsive', json_decode($record->components, true));
            $pc = Db::table('site_theme_configurations')->where('release_id', $id)->where('component', 'pc')->first();
            self::assertSame(9, (int) $pc->revision);
            self::assertSame((in_array('pc', $types, true) ? 'pc' : 'responsive') . '客户配置', json_decode($pc->settings, true)['greeting']);
            self::assertSame(0, Db::table('site_theme_configurations')->where('component', 'responsive')->count());
            self::assertSame($mobile, (bool) Db::table('site_theme_bindings')->value('mobile_enabled'));
            self::assertSame($id, (int) Db::table('site_theme_bindings')->value('release_id'));
            $current = (new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0'))->pinned('example', $record->version, $record->digest);
            self::assertFileExists($current['directory'] . '/console/pc/index.html');
            self::assertDirectoryDoesNotExist($current['directory'] . '/console/responsive');
            self::assertFileExists($package['directory'] . '/console/' . $types[0] . '/index.html');
            self::assertSame([], $runner->migrate($configuration)->executed);
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            rmdir($root);
        }
    }

    public static function layouts(): array
    {
        return [[['responsive'], false], [['responsive', 'pc', 'mobile'], true], [['pc', 'mobile'], true]];
    }
}
