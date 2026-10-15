<?php

declare(strict_types=1);

namespace app\modules\website\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\website\contract\ThemeRegistryInterface;
use app\modules\website\contract\WebsitePathPolicyInterface;
use app\modules\website\contract\WebsiteRepositoryInterface;
use app\modules\website\validation\ThemeSchema;
use app\modules\website\validation\WebsiteSettings;
use app\shared\http\ApiErrorCode;

/** 官网与各已安装主题的设置；不开放任意模板代码编辑。 */
final readonly class ManageWebsiteService
{
    public function __construct(private WebsiteRepositoryInterface $repository, private ThemeRegistryInterface $themes, private WebsitePathPolicyInterface $paths,
        private ThemeSchema $validator, private WebsiteImageService $images, private GetSystemSettingsService $system, private AdminAccessControlService $access)
    {
    }

    /** @return array<string, mixed> 完整官网配置及只读公共品牌资料 */
    public function settings(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.page.website.view');
        return $this->repository->website() + ['schema' => WebsiteSettings::currentSchema(), 'site' => $this->system->base()];
    }

    /** @return list<array{id: string, kind: string, title: string, path: string}> 可选导航页面 */
    public function pages(AdminAccount $actor, string $id): array
    {
        $this->access->requirePermission($actor, 'admin.page.website.view');
        $package = $this->activePackage($id);
        return $this->declaredPages($package);
    }

    /** 导航分区与主题页复用宿主声明页面列表。 */
    private function declaredPages(array $package): array
    {
        $pages = [['id' => 'home', 'kind' => 'core', 'title' => '首页', 'path' => '/'], ['id' => 'products.cloud-server', 'kind' => 'core', 'title' => '云服务器', 'path' => '/products/cloud-server'], ['id' => 'console.home', 'kind' => 'core', 'title' => '会员中心', 'path' => '/console/']];
        foreach ($package['manifest']['pages'] ?? [] as $key => $page) $pages[] = ['id' => $key, 'kind' => 'custom', 'title' => $page['title'], 'path' => $page['path']];
        return $pages;
    }

    /** @param array<string, mixed> $input 修订号与完整设置
     * @return array<string, mixed> 已保存配置
     */
    public function updateSection(AdminAccount $actor, string $section, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.website.' . $section . '.view');
        $this->access->requirePermission($actor, 'admin.website.' . $section . '.update');
        $schema = WebsiteSettings::section($section);
        $this->input($input, $schema);
        $website = $this->repository->website();
        if ((int) $website['revision'] !== $input['revision']) throw new BusinessException('官网配置已更新，请重新读取后保存', ApiErrorCode::CONFLICT, 409);
        $settings = array_replace($website['settings'], $input['settings']);
        if ($section === 'navigation') $this->validateLinks($settings, $this->activePackage(snapshot: $website));
        $this->checkImages($input['settings'], $schema);
        $saved = $this->repository->saveWebsite($input['revision'], $settings);
        $saved['settings'] = array_intersect_key($saved['settings'], $schema['properties']);
        return $saved + ['schema' => $schema];
    }

    /** 各分区仅返回自己的可编辑字段；公共配置不与主题专属展示配置混存。 */
    public function section(AdminAccount $actor, string $section): array
    {
        $this->access->requirePermission($actor, 'admin.website.' . $section . '.view');
        $website = $this->repository->website();
        $schema = WebsiteSettings::section($section);
        $website['settings'] = array_intersect_key($website['settings'], $schema['properties']);
        $result = $website + ['schema' => $schema, 'site' => $this->system->base()];
        if ($section === 'navigation') $result['pages'] = $this->declaredPages($this->activePackage(snapshot: $website));
        return $result;
    }

    /** 运行时与后台共同使用已发布快照，跨 Worker 不缓存可变配置。
     * @return array<string, mixed> 已验证且不冲突的包
     */
    public function activePackage(?string $id = null, ?array $snapshot = null): array
    {
        $website = $snapshot ?? $this->repository->website();
        if ($id !== null && $id !== $website['active_theme_id']) throw new BusinessException('主题未启用', ApiErrorCode::NOT_FOUND, 404);
        $package = $this->themes->pinned($website['active_theme_id'], $website['active_theme_version'], $website['active_theme_digest']);
        $this->paths->check($package);
        return $package;
    }

    /** 安装和切换前校验完整配置、图片及导航。 */
    public function validateSettings(array $website, array $theme, array $package): void
    {
        if (strlen(json_encode([$website, $theme], JSON_THROW_ON_ERROR)) > 262144) ThemeSchema::fail('settings', '主题配置最大256KiB');
        $this->validator->validate($website, WebsiteSettings::currentSchema(), 'website_settings');
        $this->validator->validate($theme, $package['schema'], 'theme_settings');
        $this->validateLinks($website, $package);
        $this->checkImages($website, WebsiteSettings::currentSchema(), 'website_settings');
        $this->checkImages($theme, $package['schema'], 'theme_settings');
    }

    /** 切换时保留核心和外链，将同标识自定义页面指向新主题；不支持的页面链接移除。 */
    public function navigationForTheme(array $settings, array $package): array
    {
        foreach (['navigation', 'footer_links'] as $group) {
            $links = [];
            foreach ($settings[$group] as $link) {
                if ($link['target'] === 'theme_page') {
                    if (!isset($package['manifest']['pages'][$link['page_id'] ?? ''])) continue;
                    $link['theme_id'] = $package['manifest']['id'];
                }
                $links[] = $link;
            }
            $settings[$group] = $links;
        }
        return $settings;
    }

    /** @param array<string, mixed> $settings 导航配置
     * @param array<string, mixed> $package 当前包
     */
    public function validateLinks(array $settings, array $package): void
    {
        foreach (['navigation', 'footer_links'] as $group) {
            foreach ($settings[$group] as $index => $link) {
                $targetFields = match ($link['target']) { 'core' => ['label', 'target', 'core_page'], 'theme_page' => ['label', 'target', 'theme_id', 'page_id'], 'external' => ['label', 'target', 'url'] };
                $valid = array_diff(array_keys($link), $targetFields) === [];
                $valid = $valid && match ($link['target']) {
                    'core' => in_array($link['core_page'] ?? '', ['home', 'products.cloud-server', 'console.home'], true),
                    'theme_page' => ($link['theme_id'] ?? '') === $package['manifest']['id'] && isset($package['manifest']['pages'][$link['page_id'] ?? '']),
                    'external' => self::safeExternalUrl($link['url'] ?? ''),
                };
                if (!$valid) ThemeSchema::fail('settings.' . $group . '.' . $index, '请选择有效页面或填写 HTTP/HTTPS 外部链接');
            }
        }
    }

    /** 导航只支持完整 HTTP/HTTPS URL；禁止凭证、控制字符和浏览器反斜线归一化。 */
    public static function safeExternalUrl(string $url): bool
    {
        $parsed = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parsed) && in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            && !isset($parsed['user']) && !isset($parsed['pass']) && !preg_match('/[\\\\\x00-\x20\x7f]/', $url);
    }

    private function input(array $input, array $schema): void
    {
        if (array_diff(array_keys($input), ['revision', 'settings']) !== [] || !is_int($input['revision'] ?? null) || $input['revision'] < 1) ThemeSchema::fail('revision', '请提供有效修订号及完整配置');
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 131072) ThemeSchema::fail('settings', '配置最大128KiB');
        $this->validator->validate($input['settings'] ?? null, $schema);
    }

    private function checkImages(mixed $value, array $schema, string $path = 'settings'): void
    {
        if (($schema['format'] ?? '') === 'image-id') $this->images->requireStored($value, $path);
        if ($schema['type'] === 'object') foreach ($value as $key => $child) $this->checkImages($child, $schema['properties'][$key], $path . '.' . $key);
        if ($schema['type'] === 'array') foreach ($value as $key => $child) $this->checkImages($child, $schema['items'], $path . '.' . $key);
    }
}
