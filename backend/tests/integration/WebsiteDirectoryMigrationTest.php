<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\website\infrastructure\FileThemeRegistry;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 已部署主题转换目录后，配置、引用、稳定ID及设备选择不能被重建或覆盖。 */
final class WebsiteDirectoryMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261011001400;
        $this->initializeDatabase();
    }

    public function testInstalledWebsiteDirectoryUpgradePreservesAllConfiguration(): void
    {
        $root = sys_get_temp_dir() . '/old-website-directory-' . bin2hex(random_bytes(6));
        $id = 'directory-' . bin2hex(random_bytes(4));
        $source = base_path('themes/default');
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $name = substr($file->getPathname(), strlen($source) + 1);
                if (str_starts_with($name, 'website/')) $name = 'template/' . substr($name, strlen('website/'));
                $path = $root . '/' . $id . '/' . $name;
                if (!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
                copy($file->getPathname(), $path);
            }
            $manifestPath = $root . '/' . $id . '/theme.json';
            $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $manifest['id'] = $id;
            $manifest['name'] = '已部署的第三方主题';
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $legacy = new FileThemeRegistry($root, runtime_path('website-themes'), public_path('theme-assets'), '0.1.0', historicalWebsite: true);
            $package = $legacy->read($id);
            $legacy->publish($package);
            $now = date('Y-m-d H:i:s');
            $releaseId = $this->row('site_theme_releases', ['theme_id' => $id, 'name' => $manifest['name'], 'version' => $manifest['version'], 'digest' => $package['digest'], 'components' => '["website","pc","mobile"]', 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $settings = $package['defaults'];
            $settings['global']['hero_title'] = '客户的独立官网内容';
            foreach (['website' => $settings, 'pc' => $package['console']['pc']['defaults'], 'mobile' => $package['console']['mobile']['defaults']] as $type => $values) {
                $this->row('site_theme_configurations', ['release_id' => $releaseId, 'component' => $type, 'settings' => json_encode($values, JSON_THROW_ON_ERROR), 'revision' => 9, 'created_at' => $now, 'updated_at' => $now]);
            }
            Db::table('site_theme_bindings')->where('id', 1)->update(['release_id' => $releaseId, 'mobile_enabled' => true]);
            Db::table('website_settings')->where('id', 1)->update(['active_release_id' => $releaseId]);
            $configurations = Db::table('site_theme_configurations')->orderBy('id')->get()->all();
            $publicSettings = Db::table('website_settings')->value('settings');
            $references = Db::table('file_references')->orderBy('file_id')->orderBy('scope')->orderBy('owner')->get()->all();
            $before = Db::table('site_theme_bindings')->where('id', 1)->first();
            $registry = new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0');
            self::assertFileExists($registry->pinned($id, $manifest['version'], $package['digest'])['directory'] . '/template/index.html.twig');

            $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
            $database = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
            self::assertSame([20261012000100], $runner->migrate($database)->executed);
            $record = Db::table('site_theme_releases')->where('id', $releaseId)->first();
            self::assertNotSame($package['digest'], $record->digest);
            self::assertSame($manifest['version'], $record->version);
            $converted = $registry->pinned($id, $record->version, $record->digest);
            self::assertFileExists($converted['directory'] . '/website/index.html.twig');
            self::assertDirectoryDoesNotExist($converted['directory'] . '/template');
            self::assertSame($package['schema'], $converted['schema']);
            self::assertSame($package['defaults'], $converted['defaults']);
            self::assertFileExists(public_path('theme-assets/' . $id . '/' . $record->version . '-' . $record->digest . '/css/main.css'));
            self::assertEquals($configurations, Db::table('site_theme_configurations')->orderBy('id')->get()->all());
            self::assertSame($publicSettings, Db::table('website_settings')->value('settings'));
            self::assertEquals($references, Db::table('file_references')->orderBy('file_id')->orderBy('scope')->orderBy('owner')->get()->all());
            $after = Db::table('site_theme_bindings')->where('id', 1)->first();
            self::assertSame($before->release_id, $after->release_id);
            self::assertSame($before->default_release_id, $after->default_release_id);
            self::assertTrue((bool) $after->mobile_enabled);
            self::assertSame((int) $before->revision + 1, (int) $after->revision);
            self::assertSame($releaseId, (int) Db::table('website_settings')->value('active_release_id'));
            self::assertFileExists($registry->pinned($id, $manifest['version'], $package['digest'])['directory'] . '/template/index.html.twig');
            self::assertSame([], $runner->migrate($database)->executed);
        } finally {
            if (is_dir($root)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                rmdir($root);
            }
        }
    }
}
