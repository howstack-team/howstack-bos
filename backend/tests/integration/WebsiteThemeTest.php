<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\storage\StorageChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\content\application\GetPublicNewsService;
use app\modules\content\infrastructure\DatabasePublicNewsRepository;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use app\modules\website\application\{ManageWebsiteService, RenderWebsiteService, WebsiteImageService};
use app\modules\website\contract\{WebsitePathPolicyInterface, WebsiteImageRepositoryInterface};
use app\modules\website\infrastructure\{DatabaseWebsiteRepository, FileThemeRegistry, TwigWebsiteRenderer};
use app\modules\website\validation\ThemeSchema;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实迁移、乐观锁、匿名内容边界及公开页面渲染；MySQL模式验证中文注释。 */
final class WebsiteThemeTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testDefaultMigrationAndConfigurationRevisionConflict(): void
    {
        $repository = new DatabaseWebsiteRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $before = $repository->website();
        self::assertSame('default', $before['active_theme_id']);
        self::assertSame(1, $before['revision']);
        self::assertSame(0, Db::table('system_settings')->where('setting_key', 'site.subtitle')->count());
        $settings = $before['settings'];
        $settings['navigation'][0]['label'] = '官网首页';
        $after = $repository->saveWebsite(1, $settings);
        self::assertSame(2, $after['revision']);
        self::assertSame('官网首页', $after['settings']['navigation'][0]['label']);
        try { $repository->saveWebsite(1, $before['settings']); self::fail('旧修订不能覆盖'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame($after, $repository->website());
    }

    public function testPublicRenderingEscapesTextAndRespectsPageMethodAndPath(): void
    {
        [$management, $render, $repository] = $this->services();
        $website = $repository->website();
        $website['settings']['company']['introduction'] = '<script>alert("private")</script>';
        $repository->saveWebsite(1, $website['settings']);
        $company = $render->page('/company', 'GET');
        self::assertSame(200, $company['status']);
        self::assertStringContainsString('&lt;script&gt;', $company['html']);
        self::assertStringNotContainsString('<script>alert', $company['html']);
        self::assertStringNotContainsString('TOP-SECRET', $company['html']);
        self::assertSame(200, $render->page('/', 'GET')['status']);
        self::assertSame(404, $render->page('/not-registered', 'GET')['status']);
        self::assertSame(405, $render->page('/company', 'POST')['status']);
        self::assertSame('/company?q=1', $render->page('/company/', 'GET', 'q=1')['location']);
        self::assertSame(200, $render->page('/company', 'HEAD')['status']);
        self::assertCount(4, $management->pages($this->founder(), 'default'));
        self::assertSame(200, $render->page('/products/cloud-server', 'GET')['status']);
    }

    public function testPermissionsAndInvalidNavigationAreRejectedBeforePersistence(): void
    {
        [$management, , $repository] = $this->services();
        $normal = new AdminAccount(99, 'normal', '', '', 1, null);
        try { $management->settings($normal); self::fail('无权限读取必须拒绝'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        $settings = array_intersect_key($repository->website()['settings'], array_flip(['navigation', 'footer_links']));
        $settings['navigation'] = [['label' => '恶意链接', 'target' => 'external', 'url' => 'javascript:alert(1)']];
        try { $management->updateSection($this->founder(), 'navigation', ['revision' => 1, 'settings' => $settings]); self::fail('恶意导航必须拒绝'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame(1, $repository->website()['revision']);
        $settings['navigation'] = [['label' => '不存在', 'target' => 'theme_page', 'theme_id' => 'default', 'page_id' => 'missing']];
        $this->expectException(BusinessException::class);
        $management->updateSection($this->founder(), 'navigation', ['revision' => 1, 'settings' => $settings]);
    }

    public function testThemeSaveAndImageAvailabilityWithoutDefaultStorage(): void
    {
        [$management, , $repository, $images] = $this->services();
        self::assertFalse($images->availability($this->founder())['available']);
        $settings = $repository->snapshot()['theme']['settings'];
        $settings['global']['hero_title'] = '新的主标题';
        self::assertSame(2, $this->themeService()->saveConfiguration($this->founder(), $repository->website()['active_release_id'], 'website', ['revision' => 1, 'settings' => $settings])['revision']);
        $settings['global']['banners'] = [['title' => '图片', 'description' => '', 'image_id' => str_repeat('a', 32)]];
        $this->expectException(BusinessException::class);
        $this->themeService()->saveConfiguration($this->founder(), $repository->website()['active_release_id'], 'website', ['revision' => 2, 'settings' => $settings]);
    }

    public function testPublicNewsDoesNotLeakDraftFutureOrDisabledCategory(): void
    {
        $now = '2026-10-01 00:00:00';
        foreach ([1, 0] as $status) {
            $category = $this->row('content_categories', ['kind' => 'news', 'name' => '测试', 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
            foreach ([['published', '2026-09-30 00:00:00'], ['draft', $now], ['published', '2099-01-01 00:00:00']] as [$state, $published]) {
                $this->row('content_entries', ['kind' => 'news', 'category_id' => $category, 'title' => '摘要', 'summary' => '公开简介', 'body' => '不能返回的正文', 'status' => $state, 'published_at' => $published, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $rows = (new DatabasePublicNewsRepository())->latest(6, $now);
        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('body', $rows[0]);
    }

    public function testMySqlSchemaCommentsAreComplete(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql') { self::markTestSkipped('中文COMMENT覆盖需MySQL模式'); }
        foreach (['website_settings', 'site_theme_configurations', 'file_assets', 'site_theme_releases', 'website_page_seo'] as $table) {
            $name = 'orm_' . $table;
            $comment = Db::selectOne('SELECT TABLE_COMMENT AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]);
            self::assertMatchesRegularExpression('/\p{Han}/u', $comment->c);
            foreach (Db::select('SELECT COLUMN_COMMENT AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]) as $column) self::assertMatchesRegularExpression('/\p{Han}/u', $column->c);
        }
    }

    public function testNewDeclaredPagePublishesDefaultsWithoutChangingCoreRoutes(): void
    {
        $temporary = sys_get_temp_dir() . '/website-extension-' . bin2hex(random_bytes(5));
        $source = base_path('themes/default');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $target = $temporary . '/source/default/' . substr($file->getPathname(), strlen($source) + 1);
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            copy($file->getPathname(), $target);
        }
        try {
            $directory = $temporary . '/source/default';
            $manifest = json_decode(file_get_contents($directory . '/theme.json'), true, flags: JSON_THROW_ON_ERROR);
            $manifest['version'] = '2.1.0';
            file_put_contents($directory . '/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $pages = json_decode(file_get_contents($directory . '/website/pages.json'), true);
            $pages['team'] = ['path' => '/company/team', 'title' => '团队介绍', 'template' => 'pages/team.html.twig'];
            file_put_contents($directory . '/website/pages.json', json_encode($pages, JSON_THROW_ON_ERROR));
            $schema = json_decode(file_get_contents($directory . '/website/settings.schema.json'), true);
            unset($schema['default'], $schema['properties']['pages']['default']);
            $schema['properties']['pages']['properties']['team'] = ['type' => 'object', 'additionalProperties' => false, 'required' => ['content'], 'properties' => ['content' => ['type' => 'string', 'maxLength' => 1000, 'default' => '团队默认介绍']]];
            file_put_contents($directory . '/website/settings.schema.json', json_encode($schema, JSON_THROW_ON_ERROR));
            file_put_contents($directory . '/website/pages/team.html.twig', '{% extends "layout.html.twig" %}{% block content %}<h1>{{ page.title }}</h1><p>{{ page.settings.content }}</p>{% endblock %}');
            $registry = new FileThemeRegistry($temporary . '/source', $temporary . '/private', $temporary . '/assets', '0.1.0');
            [$management, $render, $repository] = $this->services($registry);
            $settings = $repository->snapshot()['theme']['settings'];
            $settings['global']['hero_title'] = '保留的原有标题';
            \support\Container::get(\app\modules\website\contract\SiteThemeRepositoryInterface::class)->saveConfiguration($repository->website()['active_release_id'], 'website', 1, $settings);
            $publisher = new \app\modules\website\application\PublishWebsiteThemeService($registry, $repository, $this->createStub(WebsitePathPolicyInterface::class), new ThemeSchema(), $management, \support\Container::get(\app\modules\website\contract\SiteThemeRepositoryInterface::class), $this->themeService());
            $publisher->publish();
            self::assertSame('保留的原有标题', $repository->snapshot()['theme']['settings']['global']['hero_title']);
            self::assertSame('团队默认介绍', $repository->snapshot()['theme']['settings']['pages']['team']['content']);
            self::assertSame(200, $render->page('/company/team', 'GET')['status']);
            self::assertStringContainsString('团队默认介绍', $render->page('/company/team', 'GET')['html']);
            self::assertCount(5, $management->pages($this->founder(), 'default'));
            $release = \app\modules\website\infrastructure\model\SiteThemeReleaseModel::query()->where('theme_id', 'default')->where('version', '2.1.0')->firstOrFail();
            self::assertSame($repository->website()['active_theme_digest'], $release->digest);
            $versionSettings = \app\modules\website\infrastructure\model\SiteThemeConfigurationModel::query()->where('release_id', $release->id)->where('component', 'website')->firstOrFail();
            self::assertSame('保留的原有标题', $versionSettings->settings['global']['hero_title']);
            $pointer = $repository->website();
            file_put_contents($directory . '/website/pages/team.html.twig', '<h1>不能覆盖已安装版本</h1>');
            $publisher->publish();
            self::assertSame($pointer['active_release_id'], $repository->website()['active_release_id']);
            self::assertNotSame($pointer['active_theme_digest'], $repository->website()['active_theme_digest']);
            self::assertSame(1, \support\Db::table('site_theme_releases')->where('theme_id', 'default')->count());
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($temporary);
        }
    }

    public function testUploadUsesPrivateLocalStorageAndOnlyReturnsPublicImageId(): void
    {
        $directory = realpath(sys_get_temp_dir()) . '/website-images-' . bin2hex(random_bytes(5));
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $row = ['id' => 7, 'revision' => 2, 'status' => 1, 'plugin_id' => 'local_storage', 'is_default' => true, 'configuration_current' => true, 'platform_available' => true];
        $channels->method('list')->willReturn(['data' => [$row], 'total' => 1]);
        $channels->method('find')->willReturn($row);
        $channels->method('configuration')->willReturn(['namespace' => 'website-test']);
        $local = new \app\modules\channel\infrastructure\storage\PrivateLocalObjectStore($directory, str_repeat('test-key', 8));
        $adapter = new \plugins\storage\local_storage\StorageAdapter($this->createStub(\app\shared\plugin\contract\HttpClientInterface::class), $local);
        $factory = $this->createStub(\app\modules\channel\contract\TransactionAdapterFactoryInterface::class);
        $factory->method('storage')->willReturn($adapter);
        $images = new WebsiteImageService($channels, new \app\modules\website\infrastructure\DatabaseWebsiteImageRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry()),
            new StorageChannelService(new \app\modules\channel\application\ChannelExecutionConfiguration($channels), $factory), new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));
        try {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a4fkAAAAASUVORK5CYII=');
            $upload = $images->upload($this->founder(), $png, 'test.png');
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $upload['id']);
            self::assertSame('/api/public/v1/website/images/' . $upload['id'], $upload['url']);
            self::assertSame('stored', Db::table('file_assets')->where('id', $upload['id'])->value('status'));
            self::assertStringContainsString('/api/public/v1/storage/local?', $images->redirectUrl($upload['id']));
            self::assertStringNotContainsString($directory, json_encode($upload));
            $images->requireStored($upload['id'], 'settings.logo_image_id');
            $this->expectException(BusinessException::class);
            $images->upload($this->founder(), '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'bad.svg');
        } finally {
            if (is_dir($directory)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                rmdir($directory);
            }
        }
    }

    /** 只替换权限、公开品牌及外部存储契约，页面持久化和渲染使用实际实现。 */
    private function themeService(): \app\modules\website\application\ManageSiteThemeService
    {
        [$management,$render,$repository] = $this->services();
        return new \app\modules\website\application\ManageSiteThemeService(\support\Container::get(\app\modules\website\contract\SiteThemeRepositoryInterface::class),
            new \app\modules\website\infrastructure\ZipThemePackageInstaller(runtime_path('test-theme-install'),'0.1.0'), new FileThemeRegistry(base_path('themes'),runtime_path('website-themes'),public_path('theme-assets'),'0.1.0'),
            $repository,$this->createStub(WebsitePathPolicyInterface::class),$management,$render,new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));
    }

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

    private function founder(): AdminAccount
    {
        return new AdminAccount(1, 'founder', '', '', 1, null, true);
    }

    public function testSectionsHaveIndependentFieldsPermissionsAndSharedRevision(): void
    {
        [$management, $render, $repository, , $seo] = $this->services();
        $base = $management->section($this->founder(), 'base');
        $navigation = $management->section($this->founder(), 'navigation');
        self::assertArrayNotHasKey('navigation', $base['settings']);
        self::assertArrayNotHasKey('company', $navigation['settings']);
        self::assertCount(4, $navigation['pages']);
        $settings = $base['settings'];
        $settings['company']['introduction'] = '跨主题保存的公司资料';
        $saved = $management->updateSection($this->founder(), 'base', ['revision' => $base['revision'], 'settings' => $settings]);
        self::assertSame($navigation['settings'], array_intersect_key($repository->website()['settings'], $navigation['settings']));
        $saved = $seo->updatePage($this->founder(), 'home', ['revision' => $saved['revision'], 'scope' => 'core', 'settings' => ['title' => '官网专属标题', 'keywords' => '云服务器,公司', 'description' => '首页描述', 'indexable' => null]]);
        self::assertStringContainsString('<title>&lt;测试品牌&gt; - 官网专属标题</title>', $render->page('/', 'GET')['html']);
        self::assertStringContainsString('首页描述', $render->page('/', 'GET')['html']);
        self::assertStringContainsString('跨主题保存的公司资料', $render->page('/company', 'GET')['html']);
        self::assertArrayNotHasKey('introduction', $repository->snapshot()['theme']['settings']['pages']['company']);
        try { $management->updateSection($this->founder(), 'navigation', ['revision' => $navigation['revision'], 'settings' => $navigation['settings']]); self::fail('旧分区修订不能覆盖'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        try { $management->updateSection($this->founder(), 'base', ['revision' => $saved['revision'], 'settings' => $settings + ['navigation' => []]]); self::fail('不能跨分区写入'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        [$reader] = $this->services(null, ['admin.website.base.view']);
        $actor = new AdminAccount(99, 'reader', '', '', 1, null);
        self::assertSame($settings, $reader->section($actor, 'base')['settings']);
        foreach ([fn () => $reader->section($actor, 'navigation'), fn () => $reader->settings($actor), fn () => $reader->updateSection($actor, 'base', ['revision' => $saved['revision'], 'settings' => $settings])] as $operation) {
            try { $operation(); self::fail('只读账号不能越权'); }
            catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        }
    }

    /** 通过默认主题实际文件创建第三方ZIP包，清单ID与顶层目录一致。 */
    private function themeZip(string $directory, string $id, ?string $template = null): string
    {
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $archive = $directory . '/' . $id . '.zip';
        $zip = new \ZipArchive();
        $zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $root = base_path('themes/default');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = substr($file->getPathname(), strlen($root) + 1);
            $bytes = file_get_contents($file->getPathname());
            if ($name === 'theme.json') {
                $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                $manifest['id'] = $id;
                $manifest['name'] = '测试主题';
                $bytes = json_encode($manifest, JSON_THROW_ON_ERROR);
            }
            if ($name === 'templates/index.html.twig' && $template !== null) $bytes = $template;
            $zip->addFromString($id . '/' . $name, $bytes);
        }
        $zip->close();
        return $archive;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($directory);
    }

    public function testSeoCatalogInheritanceAndPerPageRendering(): void
    {
        [$management, $render, $repository, , $seo] = $this->services();
        $founder = $this->founder();
        $base = $management->section($founder, 'base');
        self::assertArrayNotHasKey('seo_title', $base['settings']);
        $catalog = $seo->catalog($founder);
        self::assertSame(['home', 'products', 'company'], array_column($catalog['pages'], 'id'));
        self::assertSame('core', $catalog['pages'][1]['scope']);
        self::assertSame('core', $catalog['pages'][0]['scope']);
        self::assertSame('theme:default', $catalog['pages'][2]['scope']);
        self::assertArrayNotHasKey('defaults', $catalog);
        self::assertSame('<测试品牌>', $catalog['pages'][0]['effective']['title']);
        $homeValues = ['title' => '首页专属副标题', 'description' => '仅首页描述', 'keywords' => '仅首页关键词', 'indexable' => null];
        $catalog = $seo->updatePage($founder, 'home', ['revision'=>$catalog['revision'], 'scope'=>'core', 'settings'=>$homeValues]);
        self::assertSame('<测试品牌> - 首页专属副标题', $catalog['pages'][0]['effective']['title']);
        self::assertStringContainsString('<title>&lt;测试品牌&gt; - 首页专属副标题</title>', $render->page('/', 'GET')['html']);
        self::assertSame('', $catalog['pages'][2]['effective']['description']);
        self::assertSame('', $catalog['pages'][2]['effective']['keywords']);
        $page = $catalog['pages'][2];
        $value = ['title' => '<公司SEO>', 'description' => '公司独立描述', 'keywords' => '公司,服务', 'indexable' => false];
        $saved = $seo->updatePage($founder, 'company', ['revision' => $catalog['revision'], 'scope' => $page['scope'], 'settings' => $value]);
        self::assertSame($catalog['revision'] + 1, $saved['revision']);
        self::assertSame($value, $saved['pages'][2]['settings']);
        self::assertSame(array_replace($value, ['title' => '<公司SEO> - <测试品牌>']), $saved['pages'][2]['effective']);
        self::assertSame('override', $saved['pages'][2]['sources']['indexable']);
        $html = $render->page('/company', 'GET')['html'];
        self::assertStringContainsString('<title>&lt;公司SEO&gt; - &lt;测试品牌&gt;</title>', $html);
        self::assertStringContainsString('content="公司独立描述"', $html);
        self::assertStringContainsString('content="noindex,nofollow"', $html);
        self::assertStringContainsString('href="https://example.test/company"', $html);
        self::assertStringContainsString('<title>&lt;测试品牌&gt; - 首页专属副标题</title>', $render->page('/', 'GET')['html']);
        self::assertArrayNotHasKey('seo_title', $repository->website()['settings']);
        self::assertSame($base['settings'], $management->section($founder, 'base')['settings']);
        $inherited = $seo->updatePage($founder, 'company', ['revision' => $saved['revision'], 'scope' => $page['scope'],
            'settings' => ['title' => '  ', 'description' => '', 'keywords' => '', 'indexable' => null]]);
        self::assertSame('', $inherited['pages'][2]['effective']['description']);
        self::assertTrue($inherited['pages'][2]['effective']['indexable']);
        self::assertSame('page_default', $inherited['pages'][2]['sources']['title']);
        self::assertStringNotContainsString('name="robots"', $render->page('/company', 'GET')['html']);
        self::assertStringContainsString('noindex,nofollow', $render->page('/not-exists', 'GET')['html']);
    }

    public function testSeoPermissionsInvalidFieldsAndConcurrentWrites(): void
    {
        [$management, , $repository, , $seo] = $this->services();
        $catalog = $seo->catalog($this->founder());
        $input = ['revision' => $catalog['revision'], 'scope' => 'theme:default', 'settings' => $catalog['pages'][2]['settings']];
        foreach ([['scope' => 'theme:other'], ['settings' => array_replace($input['settings'], ['indexable' => 1])],
            ['settings' => array_replace($input['settings'], ['title' => str_repeat('字', 121)])], ['settings' => $input['settings'] + ['canonical' => 'https://evil.test']], ['path' => '/arbitrary']] as $invalid) {
            try { $seo->updatePage($this->founder(), 'company', array_replace($input, $invalid)); self::fail('无效字段必须拒绝'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
        foreach (['missing', 'console_home'] as $id) {
            try { $seo->updatePage($this->founder(), $id, $input); self::fail('未注册官网页必须拒绝'); }
            catch (BusinessException $error) { self::assertSame(404, $error->httpStatus()); }
        }
        self::assertCount(1, $repository->pageSeo('default'));
        self::assertSame('home', $repository->pageSeo('default')[0]['page_id']);
        $actor = new AdminAccount(99, 'reader', '', '', 1, null);
        [, , , , $reader] = $this->services(null, ['admin.website.seo.view']);
        self::assertCount(3, $reader->catalog($actor)['pages']);
        try { $reader->updatePage($actor, 'company', $input); self::fail('只读账号不能写'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        try { $seo->catalog($actor); self::fail('无权限不能读'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        $seo->updatePage($this->founder(), 'company', $input);
        try { $seo->updatePage($this->founder(), 'company', $input); self::fail('过期修订不能覆盖'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        try { (new DatabaseWebsiteRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry()))->savePageSeo($input['revision'], 'theme:default', 'company', $input['settings']); self::fail('仓储也必须原子比较'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame($input['revision'] + 1, $repository->website()['revision']);
        $base = $management->section($this->founder(), 'base');
        try { $management->updateSection($this->founder(), 'base', ['revision' => $base['revision'], 'settings' => $base['settings'] + ['seo_title' => '越权']]); self::fail('基础设置不能绕过SEO权限'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
    }

    public function testSeoThemeIsolationKeepsOverrides(): void
    {
        [$management, $render, $repository, $images, $seo] = $this->services();
        $lifecycle = $this->themeService();
        $directory = sys_get_temp_dir() . '/seo-lifecycle-' . bin2hex(random_bytes(5));
        $defaultId = $repository->website()['active_release_id'];
        try {
            $actor = $this->founder();
            $catalog = $seo->catalog($actor);
            $value = ['title' => '旧主题公司标题', 'description' => '', 'keywords' => '', 'indexable' => true];
            $catalog = $seo->updatePage($actor, 'company', ['revision' => $catalog['revision'], 'scope' => 'theme:default', 'settings' => $value]);
            $seo->updatePage($actor, 'company', ['revision' => $catalog['revision'], 'scope' => 'theme:default', 'settings' => array_replace($value, ['title' => '修改后的标题'])]);
            $other = $lifecycle->install($actor, $this->themeZip($directory, 'other'));
            $before = $repository->website();
            $lifecycle->bind($actor, ['mobile_enabled' => false, 'revision' => $lifecycle->bindings($actor)['revision'], 'release_id' => $other['id']]);
            $catalog = $seo->catalog($actor);
            self::assertSame('', $catalog['pages'][2]['settings']['title']);
            self::assertStringNotContainsString('修改后的标题', $render->page('/company', 'GET')['html']);
            $seo->updatePage($actor, 'company', ['revision' => $catalog['revision'], 'scope' => 'theme:other', 'settings' => array_replace($value, ['title' => '另一个主题'])]);
            self::assertSame('修改后的标题', array_values(array_filter($repository->pageSeo('default'), static fn(array $row): bool => $row['page_id'] === 'company'))[0]['title']);
            $before = $repository->website();
            $lifecycle->bind($actor, ['mobile_enabled' => false, 'revision' => $lifecycle->bindings($actor)['revision'], 'release_id' => $defaultId]);
            self::assertStringContainsString('<title>修改后的标题 - &lt;测试品牌&gt;</title>', $render->page('/company', 'GET')['html']);
        } finally { $this->removeDirectory($directory); }
    }
}
