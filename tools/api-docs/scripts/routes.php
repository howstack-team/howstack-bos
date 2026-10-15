<?php

declare(strict_types=1);

/** 构建期读取 Webman 实际注册路由，不启动进程或执行业务请求。 */
$backend = dirname(__DIR__, 3) . '/backend';
chdir($backend);
require $backend . '/vendor/autoload.php';
\Webman\Config::clear();
\support\App::loadAllConfig(['route']);
\Webman\Route::load([config_path()]);

$routes = [];
foreach (\Webman\Route::getRoutes() as $route) {
    if (!str_starts_with($route->getPath(), '/api/')) {
        continue;
    }
    $callback = $route->getCallback();
    $reflection = is_array($callback) ? new ReflectionMethod($callback[0], $callback[1]) : null;
    $comment = $reflection?->getDocComment() ?: '';
    $summary = [];
    foreach (preg_split('/\r?\n/', $comment) ?: [] as $line) {
        $line = trim($line, " /*\t");
        if ($line !== '' && !str_starts_with($line, '@')) {
            $summary = [$line];
            break;
        }
    }
    // 认证必须同时读取类级和方法级 Attribute，不能仅根据 URL 猜测。
    $middlewares = $route->getMiddleware() ?? [];
    if ($reflection !== null) {
        foreach ([$reflection->getDeclaringClass(), $reflection] as $owner) {
            foreach ($owner->getAttributes(\support\annotation\Middleware::class) as $attribute) {
                foreach ($attribute->newInstance()->getMiddlewares() as $middleware) {
                    $middlewares[] = is_array($middleware) ? $middleware[0] : $middleware;
                }
            }
        }
    }
    foreach ($route->getMethods() as $method) {
        $routes[] = [
            'method' => strtolower($method),
            'path' => $route->getPath(),
            'name' => $route->getName(),
            'summary' => $summary[0] ?? ($route->getName() ?: $route->getPath()),
            'middleware' => array_values(array_unique($middlewares)),
            'controller' => is_array($callback) ? $callback[0] . '::' . $callback[1] : null,
        ];
    }
}
usort($routes, static fn (array $left, array $right): int => [$left['path'], $left['method']] <=> [$right['path'], $right['method']]);
echo json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
