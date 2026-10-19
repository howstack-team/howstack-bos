<?php

declare(strict_types=1);

namespace app\modules\website\application;

use app\modules\content\application\GetPublicNewsService;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\website\contract\WebsiteRepositoryInterface;
use app\modules\website\contract\WebsiteRendererInterface;
use app\modules\website\validation\ThemeSchema;
use app\modules\website\domain\PageSeo;

/** 固定首页与清单自定义页面的数据编排；仅提供匿名公开内容。 */
final readonly class RenderWebsiteService
{
    public function __construct(private WebsiteRepositoryInterface $repository, private ManageWebsiteService $management, private WebsiteRendererInterface $renderer,
        private GetSystemSettingsService $system, private GetPublicNewsService $news, private ThemeSchema $validator,
        private \app\modules\product\application\PublicProductPurchaseService $products,
        private \app\modules\website\contract\ThemeRegistryInterface $registry,
        private \app\modules\website\contract\SiteThemeRepositoryInterface $siteThemes)
    {
    }

    /** 查找页面并返回渲染结果；未知页面为404，已声明页面其他方法为405。
     * @return array{status: int, html: string, location: string, allow: string} 页面响应资料
     */
    public function page(string $path, string $method, string $query = ''): array
    {
        $snapshot = $this->repository->snapshot();
        $package = $this->management->activePackage(snapshot: $snapshot['website']);
        return $this->renderPage($package, $snapshot['website']['settings'], $snapshot['theme']['settings'], $path, $method, $query, $snapshot['seo']);
    }

    /** 安装校验时渲染待安装包，不写入正式配置。
     * @return array{status: int, html: string, location: string, allow: string} 渲染结果
     */
    public function validatePage(array $package, array $websiteSettings, array $themeSettings, string $path): array
    {
        return $this->renderPage($package, $websiteSettings, $themeSettings, $path, 'GET', '');
    }

    /** 正式渲染和安装校验共用公开数据构造。 */
    private function renderPage(array $package, array $websiteSettings, array $themeSettings, string $path, string $method, string $query, array $pageSeo = []): array
    {
        $manifest = $package['manifest'];
        $normalized = $path === '/' ? '/' : rtrim($path, '/');
        $id = $normalized === '/' ? 'home' : null;
        $declaration = ['title' => '首页', 'path' => '/', 'template' => $manifest['templates']['home'], 'seo' => ['description' => '', 'indexable' => true]];
        if ($normalized === '/products/cloud-server') {
            $id = 'products';
            $declaration = ['title' => '云服务器', 'path' => $normalized, 'template' => 'pages/products.html.twig', 'seo' => ['indexable' => true]];
        }
        foreach ($manifest['pages'] ?? [] as $pageId => $page) {
            if ($page['path'] === $normalized) { $id = $pageId; $declaration = $page; break; }
        }
        if ($id !== null && !in_array($method, ['GET', 'HEAD'], true)) return ['status' => 405, 'html' => '', 'location' => '', 'allow' => 'GET, HEAD'];
        if ($id !== null && $normalized !== $path) return ['status' => 308, 'html' => '', 'location' => $normalized . ($query === '' ? '' : '?' . $query), 'allow' => ''];
        $website = ['settings' => $websiteSettings];
        $theme = ['settings' => $themeSettings];
        $this->validator->validate($theme['settings'], $package['schema']);
        $base = $this->system->base();
        $this->validator->validate($websiteSettings, \app\modules\website\validation\WebsiteSettings::currentSchema());
        $site = ['name' => $base['site_title'] ?: '皓量云擎', 'description' => '',
            'logo' => WebsiteImageService::publicUrl($website['settings']['logo_image_id']), 'favicon' => WebsiteImageService::publicUrl($website['settings']['favicon_image_id']),
            'copyright' => $base['copyright'], 'icp_record' => $base['icp_record'], 'public_security_record' => $base['public_security_record'],
            'company' => array_diff_key($websiteSettings['company'], ['image_id' => true]) + ['image_url' => WebsiteImageService::publicUrl($websiteSettings['company']['image_id'])]];
        $routes = ['home' => '/', 'console.home' => '/console/', 'products.cloud-server' => '/products/cloud-server'];
        foreach ($manifest['pages'] ?? [] as $pageId => $page) $routes['theme.' . $pageId] = $page['path'];
        $seo = PageSeo::resolve($id ?? 'not_found', $id === null ? ['title' => '页面不存在'] : $declaration, $site['name'],
            PageSeo::settings($pageSeo, PageSeo::scope($manifest['id'], $id ?? 'not_found'), $id ?? 'not_found'))['effective'];
        $site['description'] = $seo['description'];
        $seo['indexable'] = $id !== null && $seo['indexable'];
        $canonical = ManageWebsiteService::safeExternalUrl($base['site_url']) ? rtrim($base['site_url'], '/') . $normalized : '';
        $selectedPath = $normalized;
        $context = [
            'site' => $site, 'seo' => $seo + ['canonical' => $canonical],
            'navigation' => $this->links($website['settings']['navigation'], $manifest, $routes, $selectedPath, $base['site_url']),
            'footer_links' => $this->links($website['settings']['footer_links'], $manifest, $routes, $selectedPath, $base['site_url']),
            'theme' => ['id' => $manifest['id'], 'version' => $manifest['version'], 'settings' => $theme['settings']['global']],
            'page' => ['id' => $id ?? 'not_found', 'kind' => in_array($id, ['home', 'products'], true) ? 'core' : 'custom', 'title' => $declaration['title'], 'path' => $normalized,
                'settings' => $id === null || in_array($id, ['home', 'products'], true) ? [] : $theme['settings']['pages'][$id],
                'banners' => [], 'features' => [], 'announcements' => [], 'images' => [], 'products' => []],
        ];
        if ($id === 'home') {
            $global = $theme['settings']['global'];
            $context['page']['banners'] = array_map(static fn (array $banner): array => $banner + ['image_url' => WebsiteImageService::publicUrl($banner['image_id'])], $global['banners'] ?? []);
            $context['page']['features'] = $global['features'] ?? [];
            $context['page']['announcements'] = $this->news->latest();
        }
        if ($id === 'home' || $id === 'products') $context['page']['products'] = $this->products->products();
        // 产品页是宿主核心路由；主题可以提供此模板，缺失时使用已安装的官方模板。
        if ($id === 'products' && !is_file($package['directory'] . '/website/pages/products.html.twig')) {
            $release = $this->siteThemes->find($this->siteThemes->bindings()['default_release_id']);
            if ($release === null) throw new \RuntimeException('默认官网主题不存在');
            $package = $this->registry->pinned($release['theme_id'], $release['version'], $release['digest']);
            $context['theme']['settings'] = array_replace($package['defaults']['global'], $context['theme']['settings']);
        }
        if ($id !== null && $id !== 'home') foreach ($context['page']['settings'] as $field => $value) {
            if (($package['schema']['properties']['pages']['properties'][$id]['properties'][$field]['format'] ?? '') === 'image-id') $context['page']['images'][$field] = WebsiteImageService::publicUrl($value);
        }
        $template = $id === null ? 'errors/404.html.twig' : $declaration['template'];
        return ['status' => $id === null ? 404 : 200, 'html' => $this->renderer->render($package, $template, $context, $routes), 'location' => '', 'allow' => ''];
    }

    /** 失效导航不展示；链接由宿主解析，模板无需了解内部配置结构。
     * @return list<array{label: string, url: string, external: bool, active: bool}> 链接资料
     */
    private function links(array $links, array $manifest, array $routes, string $path, string $siteUrl): array
    {
        $result = [];
        $navigation = new \app\modules\website\domain\WebsiteNavigationPolicy();
        foreach ($links as $link) {
            $url = match ($link['target']) {
                'core' => $routes[$link['core_page'] ?? ''] ?? '',
                'theme_page' => ($link['theme_id'] ?? '') === $manifest['id'] ? ($routes['theme.' . ($link['page_id'] ?? '')] ?? '') : '',
                'external' => ManageWebsiteService::safeExternalUrl($link['url'] ?? '') ? $link['url'] : '',
                default => '',
            };
            if ($url !== '' && $navigation->allows($url, $siteUrl)) $result[] = ['label' => $link['label'], 'url' => $url, 'external' => $link['target'] === 'external', 'active' => $url === $path];
        }
        return $result;
    }
}
