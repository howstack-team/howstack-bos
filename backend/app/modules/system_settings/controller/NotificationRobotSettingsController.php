<?php

declare(strict_types=1);

namespace app\modules\system_settings\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\system_settings\application\ManageNotificationRobotSettingsService;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Put;
use support\Request;
use support\Response;

#[DisableDefaultRoute]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class NotificationRobotSettingsController
{
    public function __construct(
        private ManageNotificationRobotSettingsService $settings,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/api/admin/v1/settings/notifications', name: 'admin.settings.notifications.show')]
    public function show(Request $request): Response
    {
        return $this->responses->success($request, $this->settings->get($this->context->account($request)));
    }

    #[Put(path: '/api/admin/v1/settings/notifications', name: 'admin.settings.notifications.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request): Response
    {
        return $this->responses->success($request, $this->settings->save($this->context->account($request), $request->post()));
    }
}
