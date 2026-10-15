<?php

declare(strict_types=1);

namespace app\modules\website\controller;

use app\shared\http\middleware\SameOriginWriteMiddleware;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\website\application\ManageWebsiteSeoService;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Put, RouteGroup};
use support\{Request, Response};

/** 页面SEO管理的认证、同源写入与响应边界。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/website/seo')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class WebsiteSeoController
{
    public function __construct(private ManageWebsiteSeoService $seo,
        private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '', name: 'admin.website.seo')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->seo->catalog($this->context->account($request)));
    }

    #[Put(path: '/pages/{id:[a-z][a-z0-9_]{0,63}}', name: 'admin.website.seo-page-update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, string $id): Response
    {
        return $this->responses->success($request, $this->seo->updatePage($this->context->account($request), $id, $request->post()));
    }
}
