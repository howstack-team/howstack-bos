<?php

declare(strict_types=1);

namespace app\modules\website\application;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use app\modules\website\contract\{ThemeRegistryInterface, WebsiteRepositoryInterface, SiteThemeRepositoryInterface, WebsitePathPolicyInterface};
use app\modules\website\validation\ThemeSchema;

/** 受控 CLI 发布当前本地源码主题；ZIP安装与切换由独立管理用例完成。 */
final readonly class PublishWebsiteThemeService
{
    public function __construct(private ThemeRegistryInterface $themes, private WebsiteRepositoryInterface $repository, private WebsitePathPolicyInterface $paths, private ThemeSchema $validator, private ManageWebsiteService $management, private SiteThemeRepositoryInterface $siteThemes, private ManageSiteThemeService $themesManagement)
    {
    }

    /** 先校验配置与路径，再发布资源，最后原子切换摘要；失败不覆盖当前主题。
     * @return array{id: string, version: string, digest: string} 发布结果
     */
    public function publish(): array
    {
        $website = $this->repository->website();
        $binding = $this->siteThemes->bindings();
        if ($binding['release_id'] !== $website['active_release_id']) {
            throw new BusinessException('当前官网使用默认组件，请通过主题管理安装并切换新版本', ApiErrorCode::CONFLICT, 409);
        }
        $package = $this->themes->read($website['active_theme_id']);
        $this->paths->check($package);
        $theme = $this->siteThemes->configuration($website['active_release_id'], 'website');
        $settings = (new \app\modules\website\validation\ThemeConfigurationMerger())->merge($package['schema'], $package['defaults'], $theme['settings']);
        $this->validator->validate($settings, $package['schema']);
        $this->management->validateLinks($website['settings'], $package);
        $this->themes->publish($package);
        if (!hash_equals($website['active_theme_digest'], $package['digest'])) {
            $this->themesManagement->installPackage($package, null);
        }
        return ['id' => $package['manifest']['id'], 'version' => $package['manifest']['version'], 'digest' => $package['digest']];
    }
}
