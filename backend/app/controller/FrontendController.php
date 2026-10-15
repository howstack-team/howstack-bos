<?php

declare(strict_types=1);

namespace app\controller;

use app\modules\install\contract\InstallationStateRepositoryInterface;
use support\Request;
use support\Response;

/**
 * 提供会员控制台和运营后台单页应用入口。
 */
final readonly class FrontendController
{
    /**
     * 初始化前端应用入口控制器。
     * @param InstallationStateRepositoryInterface $stateRepository 安装状态仓储
     */
    public function __construct(private InstallationStateRepositoryInterface $stateRepository, private \app\modules\website\application\ConsoleThemeRuntimeService $themes)
    {
    }

    /**
     * 返回会员控制台入口页面。
     * @return Response 会员控制台 HTML 响应
     */
    public function console(Request $request): Response
    {
        return response($this->themes->page((string) $request->cookie('console_presentation', 'auto'), (string) $request->header('user-agent', '')), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * 校验动态后台路径并返回运营后台入口页面。
     * @param string $path 当前请求路径
     * @return Response 运营后台 HTML 响应或 404 响应
     */
    public function admin(string $path): Response
    {
        $firstSegment = explode('/', trim($path, '/'))[0] ?? '';
        if (!hash_equals($this->stateRepository->adminPath(), $firstSegment)) {
            return response('Not Found', 404);
        }

        return $this->serve('admin');
    }

    /** 将旧书签重定向到无 /app 前缀的入口，保留查询参数。 */
    public function redirectLegacy(Request $request, string $app, string $path = ''): Response
    {
        if (!in_array($app, ['admin', 'console'], true)) {
            return response('Not Found', 404);
        }

        $isAsset = str_starts_with($path, 'assets/')
            || in_array($path, ['logo.png', 'favicon.svg', 'icons.svg'], true);
        $base = $app === 'admin' && !$isAsset ? $this->stateRepository->adminPath() : $app;
        $location = '/' . $base . ($path === '' ? '' : '/' . $path);
        $query = $request->queryString();
        if ($query !== '') {
            $location .= '?' . $query;
        }

        return response('', 308, ['Location' => $location, 'Cache-Control' => 'no-cache']);
    }

    /**
     * 读取指定前端应用的构建入口。
     * @param string $application 前端应用目录标识
     * @return Response 前端 HTML 响应或资源不可用响应
     */
    private function serve(string $application): Response
    {
        $path = public_path() . "/{$application}/index.html";
        if (!is_file($path)) {
            return response('Frontend application is not built', 503);
        }

        $html = file_get_contents($path);
        if ($html === false) {
            return response('Frontend application is unavailable', 503);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
