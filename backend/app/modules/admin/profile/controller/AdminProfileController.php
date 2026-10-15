<?php

declare(strict_types=1);

namespace app\modules\admin\profile\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\admin\profile\application\ManageAdminProfileService;
use app\modules\admin\profile\validation\AdminProfileValidator;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\Put;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

/** 提供当前登录管理员的个人设置接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/profile')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminProfileController
{
    public function __construct(
        private ManageAdminProfileService $profiles,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '', name: 'admin.profile.show')]
    public function show(Request $request): Response
    {
        return $this->responses->success($request, $this->profiles->profile($this->context->account($request)))
            ->withHeader('Cache-Control', 'no-store');
    }

    #[Put(path: '', name: 'admin.profile.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request): Response
    {
        return $this->responses->success($request, $this->profiles->update(
            $this->context->account($request), AdminProfileValidator::profile($request->post()),
        ), '个人资料已保存')->withHeader('Cache-Control', 'no-store');
    }

    #[Post(path: '/password', name: 'admin.profile.password')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function password(Request $request): Response
    {
        $data = AdminProfileValidator::password($request->post());
        $this->profiles->changePassword(
            $this->context->account($request), $data['current_password'], $data['new_password'],
        );

        return $this->responses->success($request, null, '密码已修改，请重新登录')
            ->cookie(
                (string) config('authentication.admin.cookie_name', 'hlyq_admin_token'),
                '', 0, '/', '', (bool) config('authentication.admin.secure_cookie', false), true, 'Strict',
            )->withHeader('Cache-Control', 'no-store');
    }
}
