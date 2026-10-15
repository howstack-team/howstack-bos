<?php

declare(strict_types=1);

namespace app\modules\system_settings\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\system_settings\application\ManageAdminEntryService;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Put, RouteGroup};
use support\Request;
use support\Response;

/** 系统安全设置中的后台访问入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminEntryController
{
    public function __construct(private ManageAdminEntryService $settings, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '/settings/admin-entry', name: 'admin.settings.admin-entry.show')]
    public function show(Request $request): Response
    {
        return $this->responses->success($request, $this->settings->get($this->context->account($request)));
    }

    #[Put(path: '/settings/admin-entry', name: 'admin.settings.admin-entry.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request): Response
    {
        return $this->responses->success($request, $this->settings->save($this->context->account($request), $request->post()));
    }
}
