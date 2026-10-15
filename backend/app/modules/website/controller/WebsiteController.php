<?php

declare(strict_types=1);

namespace app\modules\website\controller;

use app\modules\website\application\RenderWebsiteService;
use support\annotation\route\DisableDefaultRoute;
use support\Request;
use support\Response;

/** 官网入口由根路由和受控 fallback 分发，不在请求过程中创建路由。 */
#[DisableDefaultRoute]
final readonly class WebsiteController
{
    public function __construct(private RenderWebsiteService $pages)
    {
    }

    /** 返回官网 HTML、标准方法错误或静态路径规范化重定向。 */
    public function show(Request $request): Response
    {
        $page = $this->pages->page($request->path(), $request->method(), $request->queryString());
        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin'];
        if ($page['location'] !== '') $headers['Location'] = $page['location'];
        if ($page['allow'] !== '') $headers['Allow'] = $page['allow'];
        return new Response($page['status'], $headers, $request->method() === 'HEAD' ? '' : $page['html']);
    }
}
