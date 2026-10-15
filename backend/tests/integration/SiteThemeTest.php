<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\channel\application\storage\StorageChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\content\application\GetPublicNewsService;
use app\modules\content\infrastructure\DatabasePublicNewsRepository;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use app\modules\website\application\{ManageWebsiteService, RenderWebsiteService, WebsiteImageService};
use app\modules\website\contract\{WebsitePathPolicyInterface, WebsiteImageRepositoryInterface};
use app\modules\website\infrastructure\{DatabaseWebsiteRepository, TwigWebsiteRenderer};
use app\modules\website\validation\ThemeSchema;
use app\exception\BusinessException;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\website\application\ManageSiteThemeService;
use app\modules\website\application\ConsoleThemeRuntimeService;
use app\modules\website\contract\SiteThemeRepositoryInterface;
use app\modules\website\contract\WebsiteRepositoryInterface;
use app\modules\website\infrastructure\FileThemeRegistry;
use PHPUnit\Framework\TestCase;
use support\Container;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 实际迁移与ZIP、版本配置、整套绑定事务、回退和卸载。 */
final class SiteThemeTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function services(?\app\modules\website\contract\ThemeRegistryInterface $registry = null, array $permissions = []): array
    {
        $repository = new DatabaseWebsiteRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $themes = $registry ?? new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0');
        $paths = $this->createStub(WebsitePathPolicyInterface::class);
        $validator = new ThemeSchema();
        $grant = $this->createStub(AdminAccessRepositoryInterface::class);
        $grant->method('grantsForAdmin')->willReturn([['permissions' => $permissions]]);
        $access = new AdminAccessControlService($grant);
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('list')->willReturn(['data' => [], 'total' => 0]);
        $imageRepository = $this->createStub(WebsiteImageRepositoryInterface::class);
        $imageRepository->method('find')->willReturn(null);
        $images = new WebsiteImageService($channels, $imageRepository, new StorageChannelService(new \app\modules\channel\application\ChannelExecutionConfiguration($channels), $this->createStub(\app\modules\channel\contract\TransactionAdapterFactoryInterface::class)), $access);
        $settings = $this->createStub(SystemSettingsRepositoryInterface::class);
        $settings->method('values')->willReturn(['site.title' => '<测试品牌>', 'site.url' => 'https://example.test', 'security.secret' => 'TOP-SECRET']);
        $system = new GetSystemSettingsService($settings);
        $management = new ManageWebsiteService($repository, $themes, $paths, $validator, $images, $system, $access);
        $render = new RenderWebsiteService($repository, $management, new TwigWebsiteRenderer(runtime_path('twig-website')), $system, new GetPublicNewsService(new DatabasePublicNewsRepository()), $validator, \support\Container::get(\app\modules\product\application\PublicProductPurchaseService::class), $themes, \support\Container::get(\app\modules\website\contract\SiteThemeRepositoryInterface::class));
        return [$management, $render, $repository, $images, new \app\modules\website\application\ManageWebsiteSeoService($repository, $management, $system, $access)];
    }

    private function service(): ManageSiteThemeService
    {
        [$management, $render, $website] = $this->services();
        return new ManageSiteThemeService(Container::get(SiteThemeRepositoryInterface::class),
            new \app\modules\website\infrastructure\ZipThemePackageInstaller(runtime_path('website-theme-install'), '0.1.0'),
            new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0'),
            $website, $this->createStub(WebsitePathPolicyInterface::class), $management, $render,
            new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));
    }

    private function runtime(): ConsoleThemeRuntimeService
    {
        $settings = $this->createStub(SystemSettingsRepositoryInterface::class);
        $settings->method('values')->willReturn(['site.title' => '测试网站', 'security.secret' => 'NOT-PUBLIC']);
        return new ConsoleThemeRuntimeService(Container::get(SiteThemeRepositoryInterface::class),
            new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0'), new GetSystemSettingsService($settings), Container::get(WebsiteRepositoryInterface::class));
    }

    private function founder(): AdminAccount
    {
        return new AdminAccount(1, 'founder', '', '', 1, null, true);
    }

    public function testBrandingReadsCurrentPublicSettingsAndEscapesHtmlInjection(): void
    {
        $base = ['site.title' => '新平台</script>', 'site.copyright' => '© 测试版权',
            'site.icp_record' => '京ICP备测试号', 'site.public_security_record' => '京公网安备测试号',
            'security.secret' => 'NOT-PUBLIC'];
        $pictures = ['logo_image_id' => str_repeat('a', 32), 'favicon_image_id' => str_repeat('b', 32)];
        $settings = $this->createStub(SystemSettingsRepositoryInterface::class);
        $settings->method('values')->willReturnCallback(static function () use (&$base): array { return $base; });
        $website = $this->createStub(WebsiteRepositoryInterface::class);
        $website->method('website')->willReturnCallback(static function () use (&$pictures): array { return ['settings' => $pictures]; });
        $runtime = new ConsoleThemeRuntimeService(Container::get(SiteThemeRepositoryInterface::class),
            new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0'),
            new GetSystemSettingsService($settings), $website);
        self::assertSame([
            'name' => '新平台</script>', 'logo' => '/api/public/v1/website/images/' . str_repeat('a', 32),
            'favicon' => '/api/public/v1/website/images/' . str_repeat('b', 32),
            'copyright' => '© 测试版权', 'icp_record' => '京ICP备测试号', 'public_security_record' => '京公网安备测试号',
        ], $runtime->bootstrap()['site']);
        $html = $runtime->page('auto', '');
        self::assertStringNotContainsString('新平台</script>', $html);
        self::assertStringNotContainsString('NOT-PUBLIC', $html);
        $base['site.title'] = '更新后的平台';
        $base['site.copyright'] = '';
        $pictures['logo_image_id'] = '';
        self::assertSame('更新后的平台', $runtime->bootstrap()['site']['name']);
        self::assertSame('', $runtime->bootstrap()['site']['copyright']);
        self::assertSame('', $runtime->bootstrap()['site']['logo']);
    }

    public function testSingleConfigurationSourceAndOrdinarySpa(): void
    {
        $site = Container::get(WebsiteRepositoryInterface::class)->website();
        self::assertSame('default', $site['active_theme_id']);
        self::assertFalse(Db::connection()->getSchemaBuilder()->hasTable('website_themes'));
        self::assertFalse(Db::connection()->getSchemaBuilder()->hasTable('website_theme_settings'));
        self::assertFalse(Db::connection()->getSchemaBuilder()->hasColumn('website_settings', 'active_theme_digest'));
        $runtime = $this->runtime();
        $data = $runtime->bootstrap();
        self::assertSame('/console', $data['basePath']); self::assertSame('pc', $data['theme']['type']);
        self::assertArrayNotHasKey('entries', $data); self::assertArrayNotHasKey('root', $data);
        self::assertStringNotContainsString('NOT-PUBLIC', json_encode($data));
        $html = $runtime->page('auto', '');
        self::assertStringContainsString('<base href="/theme-assets/', $html);
        self::assertStringContainsString('id="console-theme-config"', $html);
        self::assertStringNotContainsString('theme-host.js', $html);
        self::assertStringNotContainsString('export function mount', $html);
        $service = $this->service();
        $config = $service->configuration($this->founder(), $site['active_release_id'], 'website');
        $config['settings']['global']['hero_title'] = '唯一配置来源';
        $service->saveConfiguration($this->founder(), $site['active_release_id'], 'website', ['revision' => $config['revision'], 'settings' => $config['settings']]);
        self::assertSame('唯一配置来源', Container::get(WebsiteRepositoryInterface::class)->snapshot()['theme']['settings']['global']['hero_title']);
    }
    public function testUnifiedSelectionUpdatesDevicesAndMissingComponents(): void
    {
        $service = $this->service(); $actor = $this->founder(); $initial = $service->bindings($actor);
        $v1 = $this->install($service, '1.0.0');
        self::assertSame($initial, $service->bindings($actor));
        $config = $service->configuration($actor, $v1['id'], 'pc');
        $service->saveConfiguration($actor, $v1['id'], 'pc', ['revision' => $config['revision'], 'settings' => ['greeting' => '原有PC配置']]);
        $first = $service->bind($actor, ['mobile_enabled' => false, 'revision' => $initial['revision'], 'release_id' => $v1['id']]);
        self::assertSame($v1['id'], $first['website']['release_id']); self::assertSame($v1['id'], $first['console']['release_id']);
        self::assertSame('pc', $this->runtime()->bootstrap('auto', 'iPhone Mobile')['theme']['type']);
        $v2 = $this->install($service, '1.1.0');
        self::assertSame($v1['id'], $v2['id']);
        self::assertSame(1, Db::table('site_theme_releases')->where('theme_id', 'mixed-example')->count());
        self::assertSame('原有PC配置', $this->runtime()->bootstrap('pc')['settings']['greeting']);
        self::assertSame('1.1.0', $this->runtime()->bootstrap()['theme']['version']);
        try { $service->bind($actor, ['mobile_enabled' => false, 'revision' => $first['revision'], 'release_id' => $v2['id']]); self::fail('更新前的选择修订不能覆盖'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        $websiteOnly = $this->install($service, '1.2.0', 'website', 'website-example');
        $bound = $service->bind($actor, ['mobile_enabled' => false, 'revision' => $service->bindings($actor)['revision'], 'release_id' => $websiteOnly['id']]);
        self::assertFalse($bound['website']['fallback']); self::assertTrue($bound['console']['fallback']);
        self::assertSame('default', $this->runtime()->bootstrap()['theme']['id']);
        $consoleOnly = $this->install($service, '1.3.0', 'console', 'console-example');
        $bound = $service->bind($actor, ['mobile_enabled' => false, 'revision' => $bound['revision'], 'release_id' => $consoleOnly['id']]);
        self::assertTrue($bound['website']['fallback']); self::assertFalse($bound['console']['fallback']);
        [$management, , $website] = $this->services();
        $publisher = new \app\modules\website\application\PublishWebsiteThemeService(
            new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), '0.1.0'),
            $website, $this->createStub(WebsitePathPolicyInterface::class), new ThemeSchema(), $management, Container::get(SiteThemeRepositoryInterface::class), $service,
        );
        try { $publisher->publish(); self::fail('发布默认官网不能覆盖仅会员主题选择'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame($bound, $service->bindings($actor));
    }
    public function testPermissionsIdempotentInstallAndProtectedUninstall(): void
    {
        $service = $this->service(); $actor = $this->founder(); $initial = $service->bindings($actor);
        $v1 = $this->install($service, '1.0.0'); $v2 = $this->install($service, '1.1.0', 'both', 'other-example');
        $normal = new AdminAccount(99, 'normal', '', '', 1, null);
        foreach ([fn () => $service->bind($normal, ['mobile_enabled' => false, 'revision'=>$initial['revision'],'release_id'=>$v1['id']]), fn () => $service->uninstall($normal, $v1['id'])] as $call) {
            try { $call(); self::fail('必须检查权限'); } catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        }
        self::assertSame($initial, $service->bindings($actor));
        self::assertSame($v1, $this->install($service, '1.0.0'));
        $service->bind($actor, ['mobile_enabled' => false, 'revision'=>$initial['revision'],'release_id'=>$v1['id']]);
        try { $service->uninstall($actor,$v1['id']); self::fail('正在使用不能删除'); } catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        $service->uninstall($actor,$v2['id']); self::assertNull(Container::get(SiteThemeRepositoryInterface::class)->find($v2['id']));
        $config = $service->configuration($actor, $v1['id'], 'pc');
        try { $service->saveConfiguration($actor,$v1['id'],'pc',['revision'=>$config['revision'],'settings'=>['unknown'=>true]]); self::fail('未知字段不能保存'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame($config, $service->configuration($actor,$v1['id'],'pc'));
    }
    public function testSameVersionUpdateDefaultsIncompatibilityAndConcurrentConfiguration(): void
    {
        $service = $this->service(); $actor = $this->founder(); $v1 = $this->install($service, '1.0.0');
        $config = $service->configuration($actor, $v1['id'], 'pc');
        $service->saveConfiguration($actor, $v1['id'], 'pc', ['revision' => $config['revision'], 'settings' => ['greeting' => '客户文字']]);
        $oldPackage = $service->package($v1['id']);
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['greeting' => ['type' => 'string', 'maxLength' => 80, 'default' => '默认'], 'color' => ['type' => 'string', 'maxLength' => 20, 'default' => 'blue']], 'required' => ['greeting', 'color']];
        $v2 = $this->install($service, '1.0.0', 'both', 'mixed-example', ['console/pc/settings.schema.json' => json_encode($schema)]);
        self::assertSame($v1['id'], $v2['id']); self::assertNotSame($v1['digest'], $v2['digest']);
        self::assertEquals(['greeting' => '客户文字', 'color' => 'blue'], $service->configuration($actor, $v2['id'], 'pc')['settings']);
        self::assertFileExists($oldPackage['directory'] . '/console/pc/index.html');
        $before = $service->configuration($actor, $v2['id'], 'pc'); $binding = $service->bindings($actor);
        try { $this->install($service, '1.1.0', 'both', 'mixed-example', ['console/pc/settings.schema.json' => '{"type":"object","additionalProperties":false,"properties":{"greeting":{"type":"integer","default":1}},"required":["greeting"]}']); self::fail('不兼容配置须拒绝'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame($before, $service->configuration($actor, $v2['id'], 'pc')); self::assertSame($binding, $service->bindings($actor));
        self::assertSame($v2['digest'], $service->package($v2['id'])['digest']);
        $repository = Container::get(SiteThemeRepositoryInterface::class); $state = $repository->installation('mixed-example');
        $service->saveConfiguration($actor, $v2['id'], 'pc', ['revision' => $before['revision'], 'settings' => ['greeting' => '并发保存', 'color' => 'red']]);
        try { $repository->install($service->package($v2['id']), null, $state, [], $state['website']['active_release_id'], $state['website']['settings']); self::fail('旧快照不能覆盖并发保存'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame('并发保存', $service->configuration($actor, $v2['id'], 'pc')['settings']['greeting']);
    }
    public function testNewMysqlTablesAndEveryColumnHaveComments(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql') self::markTestSkipped('中文注释需MySQL');
        foreach (['site_theme_releases','site_theme_configurations','site_theme_bindings','website_settings'] as $name) {
            foreach (Db::select('SHOW FULL COLUMNS FROM ' . Db::connection()->getTablePrefix() . $name) as $column) self::assertNotSame('', $column->Comment);
        }
    }

    public function testMobileCapabilitySwitchAndForcedPreference(): void
    {
        $service = $this->service(); $actor = $this->founder();
        $pc = $this->install($service, '1.0.0', 'pc', 'pc-only');
        self::assertContains('pc', $pc['components']); self::assertNotContains('mobile', $pc['components']);
        $binding = $service->bind($actor, ['revision' => $service->bindings($actor)['revision'], 'release_id' => $pc['id'], 'mobile_enabled' => false]);
        self::assertFalse($binding['console']['mobile_available']); self::assertFalse($binding['mobile_enabled']);
        try { $service->bind($actor, ['revision' => $binding['revision'], 'release_id' => $pc['id'], 'mobile_enabled' => true]); self::fail('无移动模板不能开启'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame($binding, $service->bindings($actor));
        self::assertSame('pc', $this->runtime()->bootstrap('mobile', 'iPhone Mobile')['theme']['type']);
        $both = $this->install($service, '1.0.0', 'both', 'devices');
        $disabled = $service->bind($actor, ['revision' => $binding['revision'], 'release_id' => $both['id'], 'mobile_enabled' => false]);
        self::assertTrue($disabled['console']['mobile_available']);
        self::assertSame('pc', $this->runtime()->bootstrap('auto', 'iPhone Mobile')['theme']['type']);
        self::assertSame('pc', $this->runtime()->bootstrap('mobile')['theme']['type']);
        self::assertNotContains('theme.presentation.split', $this->runtime()->bootstrap()['capabilities']);
        $enabled = $service->bind($actor, ['revision' => $disabled['revision'], 'release_id' => $both['id'], 'mobile_enabled' => true]);
        self::assertTrue($enabled['mobile_enabled']);
        self::assertSame('mobile', $this->runtime()->bootstrap('auto', 'iPhone Mobile')['theme']['type']);
        self::assertSame('mobile', $this->runtime()->bootstrap('mobile')['theme']['type']);
        self::assertSame('pc', $this->runtime()->bootstrap('pc', 'iPhone Mobile')['theme']['type']);
        self::assertSame('pc', $this->runtime()->bootstrap('auto', 'desktop')['theme']['type']);
        self::assertContains('theme.presentation.split', $this->runtime()->bootstrap()['capabilities']);
        try { $service->bind($actor, ['revision' => $disabled['revision'], 'release_id' => $both['id'], 'mobile_enabled' => false]); self::fail('旧修订不能覆盖移动端开关'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        try { $service->bind(new AdminAccount(99, 'normal', '', '', 1, null), ['revision' => $enabled['revision'], 'release_id' => $both['id'], 'mobile_enabled' => false]); self::fail('开启和关闭都须切换权限'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
    }

    public function testEnabledMobileCannotBeRemovedByUpdate(): void
    {
        $service = $this->service(); $actor = $this->founder();
        $empty = '{"type":"object","additionalProperties":false,"properties":{}}';
        $theme = $this->install($service, '1.0.0', 'both', 'removable-mobile', ['console/mobile/settings.schema.json' => $empty]);
        $enabled = $service->bind($actor, ['revision' => $service->bindings($actor)['revision'], 'release_id' => $theme['id'], 'mobile_enabled' => true]);
        try { $this->install($service, '1.1.0', 'pc', 'removable-mobile'); self::fail('使用中的移动端不能随更新删除'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame($enabled, $service->bindings($actor));
        self::assertSame('mobile', $this->runtime()->bootstrap('mobile')['theme']['type']);
        $service->bind($actor, ['revision' => $enabled['revision'], 'release_id' => $theme['id'], 'mobile_enabled' => false]);
        $updated = $this->install($service, '1.1.0', 'pc', 'removable-mobile');
        self::assertSame($theme['id'], $updated['id']);
        self::assertFalse($service->bindings($actor)['console']['mobile_available']);
        self::assertSame('pc', $this->runtime()->bootstrap('mobile')['theme']['type']);
    }

    private function install(ManageSiteThemeService $service, string $version, string $kind = 'both', string $themeId = 'mixed-example', array $overrides = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'mixed-theme-'); $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $root = base_path('themes/default');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            $name = substr($entry->getPathname(), strlen($root) + 1);
            if (str_starts_with($name, 'console/') || $kind === 'console' && str_starts_with($name, 'website/')) continue;
            $text = file_get_contents($entry->getPathname());
            if ($name === 'theme.json') {
                $manifest = json_decode($text, true, flags: JSON_THROW_ON_ERROR); $manifest['id'] = $themeId; $manifest['version'] = $version;
                if ($kind === 'website') unset($manifest['console']);
                $text = json_encode($manifest, JSON_THROW_ON_ERROR);
            }
            $zip->addFromString($themeId . '/' . $name, $text);
        }
        if ($kind !== 'website') foreach ($kind === 'pc' ? ['pc'] : ['pc', 'mobile'] as $type) {
            $zip->addFromString($themeId . '/console/' . $type . '/index.html', '<!doctype html><html><head><title>示例</title></head><body>示例SPA</body></html>');
            $zip->addFromString($themeId . '/console/' . $type . '/settings.schema.json', '{"type":"object","additionalProperties":false,"properties":{"greeting":{"type":"string","maxLength":80,"default":"默认展示"}},"required":["greeting"]}');
        }
        foreach ($overrides as $name => $text) $zip->addFromString($themeId . '/' . $name, $text);
        $zip->close();
        try { return $service->install($this->founder(), $file); } finally { unlink($file); }
    }
}
