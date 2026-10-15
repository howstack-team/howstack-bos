<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

use app\modules\install\contract\InstallationStateRepositoryInterface;
use app\modules\website\contract\WebsitePathPolicyInterface;
use app\modules\website\validation\ThemeSchema;
use Webman\Route;

/** 除保留前缀，还检查宿主当前静态路由，运行时不修改路由表。 */
final readonly class HostWebsitePathPolicy implements WebsitePathPolicyInterface
{
    public function __construct(private InstallationStateRepositoryInterface $state)
    {
    }

    /** @inheritDoc */
    public function check(array $package): void
    {
        foreach ($package['manifest']['pages'] ?? [] as $page) {
            FileThemeRegistry::validatePath($page['path'], [$this->state->adminPath()]);
            if (is_file(public_path() . $page['path']) || is_dir(public_path() . $page['path'])) ThemeSchema::fail('manifest.pages.path', '页面路径与公开资源冲突');
            foreach (Route::getRoutes() as $route) {
                $path = $route->getPath();
                if ($path === $page['path']) ThemeSchema::fail('manifest.pages.path', '页面路径与宿主路由冲突');
                // 动态路由的固定首段占有命名空间，避免插件被主题抢占。
                if (str_contains($path, '{') && preg_match('~^/([a-z][a-z0-9-]*)/~', $path, $match)) FileThemeRegistry::validatePath($page['path'], [$match[1]]);
            }
        }
    }
}
