<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFunction;

/** Webman 官方 View 接口的主题适配器；内部元数据不会成为 Twig 全局变量。 */
final class ThemeView implements \Webman\View
{
    /** @param array<string, mixed> $vars 由宿主构建的包、公开上下文和白名单路由 */
    public static function render(string $template, array $vars, ?string $app = null): string
    {
        $package = $vars['package'];
        $context = $vars['context'];
        $routes = $vars['routes'];
        // 历史已发布包保持原摘要，只有快照读取使用旧目录。
        $websiteDirectory = is_dir($package['directory'] . '/website') ? '/website' : (is_dir($package['directory'] . '/template') ? '/template' : '/templates');
        $assetsDirectory = $websiteDirectory === '/templates' ? '/assets/' : $websiteDirectory . '/assets/';
        $environment = new Environment(new FilesystemLoader($package['directory'] . $websiteDirectory), [
            'autoescape' => 'html', 'strict_variables' => true, 'debug' => false,
            'cache' => $vars['cache'] . '/' . $package['digest'],
        ]);
        $environment->addExtension(new SandboxExtension(new SecurityPolicy(
            ['extends', 'include', 'block', 'if', 'for'], ['escape', 'e', 'default', 'length', 'join', 'date', 'upper', 'lower', 'number_format'], [], [], ['asset', 'route'],
        ), true));
        $environment->addFunction(new TwigFunction('asset', static function (string $path) use ($package, $assetsDirectory): string {
            if (!preg_match('~^[a-zA-Z0-9_/-]+\.(?:css|js|svg|png|jpg|jpeg|webp|gif|woff2)$~D', $path)
                || str_contains($path, '..') || !is_file($package['directory'] . $assetsDirectory . $path)) throw new \InvalidArgumentException('主题资源不存在');
            return '/theme-assets/' . $package['manifest']['id'] . '/' . $package['manifest']['version'] . '-' . $package['digest'] . '/' . $path;
        }));
        $environment->addFunction(new TwigFunction('route', static function (string $name) use ($routes): string {
            return $routes[$name] ?? throw new \InvalidArgumentException('未注册的主题路由名称');
        }));
        return $environment->render($template, $context);
    }
}
