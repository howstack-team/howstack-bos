<?php

declare(strict_types=1);

namespace app\modules\website\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\website\contract\WebsiteRepositoryInterface;
use app\modules\website\domain\PageSeo;
use app\modules\website\validation\ThemeSchema;
use app\shared\http\ApiErrorCode;

/** 只配置当前主题真实声明的官网页面，不创建路由或更改模板源码。 */
final readonly class ManageWebsiteSeoService
{
    public function __construct(private WebsiteRepositoryInterface $repository, private ManageWebsiteService $website,
        private GetSystemSettingsService $system, private AdminAccessControlService $access)
    {
    }

    /** @return array<string, mixed> 逐页编辑值、生效值及来源 */
    public function catalog(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.website.seo.view');
        return $this->catalogFromSnapshot($this->repository->snapshot());
    }

    /** @return array<string, mixed> 原子保存后的完整目录，修订用于下一次提交 */
    public function updatePage(AdminAccount $actor, string $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.website.seo.view');
        $this->access->requirePermission($actor, 'admin.website.seo.update');
        if (array_diff(array_keys($input), ['revision', 'scope', 'settings']) !== [] || !is_int($input['revision'] ?? null) || $input['revision'] < 1) ThemeSchema::fail('revision', '请提供有效修订号及页面配置');
        $snapshot = $this->repository->snapshot();
        if ($snapshot['website']['revision'] !== $input['revision']) throw new BusinessException('官网配置已更新，请重新读取后保存', ApiErrorCode::CONFLICT, 409);
        $catalog = $this->catalogFromSnapshot($snapshot);
        $page = null;
        foreach ($catalog['pages'] as $candidate) if ($candidate['id'] === $id) { $page = $candidate; break; }
        if ($page === null) throw new BusinessException('官网页面不存在', ApiErrorCode::NOT_FOUND, 404);
        if (($input['scope'] ?? null) !== $page['scope']) ThemeSchema::fail('scope', '页面不属于当前主题，请重新读取');
        $settings = $input['settings'] ?? null;
        if (!is_array($settings) || array_diff(array_keys($settings), array_keys(PageSeo::emptySettings())) !== []) ThemeSchema::fail('settings', '请提供页面SEO配置对象');
        foreach (['title' => 120, 'description' => 500, 'keywords' => 255] as $field => $limit) {
            if (!is_string($settings[$field] ?? null) || mb_strlen($settings[$field]) > $limit) ThemeSchema::fail('settings.' . $field, '请填写不超过' . $limit . '字的文字');
        }
        if (!array_key_exists('indexable', $settings) || ($settings['indexable'] !== null && !is_bool($settings['indexable']))) ThemeSchema::fail('settings.indexable', '请选择继承、允许或禁止索引');
        $saved = $this->repository->savePageSeo($input['revision'], $page['scope'], $id, $settings);
        return $this->catalogFromSnapshot($saved);
    }

    private function catalogFromSnapshot(array $snapshot): array
    {
        $website = $snapshot['website'];
        $package = $this->website->activePackage(snapshot: $website);
        $name = $this->system->base()['site_title'] ?: '皓量云擎';
        $pages = ['home' => ['title' => '首页', 'path' => '/'], 'products' => ['title' => '云服务器', 'path' => '/products/cloud-server'], ...($package['manifest']['pages'] ?? [])];
        $result = [];
        foreach ($pages as $id => $declaration) {
            $scope = PageSeo::scope($website['active_theme_id'], $id);
            $settings = PageSeo::settings($snapshot['seo'], $scope, $id);
            $result[] = ['id' => $id, 'scope' => $scope, 'kind' => in_array($id, ['home', 'products'], true) ? 'core' : 'custom', 'title' => $declaration['title'],
                'path' => $declaration['path'], 'settings' => $settings] + PageSeo::resolve($id, $declaration, $name, $settings);
        }
        return ['revision' => $website['revision'], 'active_theme_id' => $website['active_theme_id'],
            'pages' => $result];
    }
}
