<?php

declare(strict_types=1);

namespace app\modules\system_settings\infrastructure;

use app\exception\BusinessException;
use app\modules\system_settings\contract\AdminEntryPathPolicyInterface;
use app\modules\website\contract\ThemeRegistryInterface;
use app\modules\website\contract\SiteThemeRepositoryInterface;
use app\shared\http\ApiErrorCode;
use Webman\Route;

/** 检查真实宿主路由及所有已安装主题，避免修改入口导致官网页面失效。 */
final readonly class HostAdminEntryPathPolicy implements AdminEntryPathPolicyInterface
{
    private const array RESERVED = ['api', 'api-docs', 'console', 'install', 'app', 'assets', 'install-assets', 'plugin-assets', 'theme-assets', 'storage', 'website-preview'];

    public function __construct(private SiteThemeRepositoryInterface $website, private ThemeRegistryInterface $themes)
    {
    }

    public function check(string $path): void
    {
        if (in_array($path, self::RESERVED, true)) $this->fail('该路径为系统保留路径，请更换');
        // /admin 是固定构建资源目录，但仍允许作为默认后台页面入口。
        if ($path !== 'admin' && file_exists(public_path() . '/' . $path)) $this->fail('该路径与公开资源冲突，请更换');
        foreach (Route::getRoutes() as $route) {
            if (preg_match('~^/([a-z][a-z0-9-]*)(?:/|\[|$)~', $route->getPath(), $match) && $match[1] === $path) {
                $this->fail('该路径与已有业务路由冲突，请更换');
            }
        }
        foreach ($this->website->themes() as $theme) {
            $package = $this->themes->pinned($theme['theme_id'], $theme['version'], $theme['digest']);
            foreach ($package['manifest']['pages'] ?? [] as $page) {
                if ((explode('/', trim($page['path'], '/'))[0] ?? '') === $path) $this->fail('该路径与已安装主题的页面冲突，请更换');
            }
        }
    }

    private function fail(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['admin_path' => [$message]]]);
    }
}
