<?php

declare(strict_types=1);

namespace app\modules\service_instance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\service_instance\application\ManageInstanceVncService;
use app\modules\service_instance\validation\InstanceOperationValidator;
use app\modules\service_instance\middleware\InstanceVncRateLimitMiddleware;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};

/** VNC连接凭据仅由认证POST签发，GET只返回当前资格。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/service-instances')]
#[Middleware(AdminAuthMiddleware::class, \app\shared\http\middleware\SameOriginWriteMiddleware::class, InstanceVncRateLimitMiddleware::class)]
final readonly class AdminInstanceVncController
{
    /**
     * @param ManageInstanceVncService $vnc 远程控制
     * @param AdminAuthenticationContext $context 认证
     * @param ApiResponse $responses 统一响应
     */
    public function __construct(private ManageInstanceVncService $vnc, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 实例路径
     * @return Response 不含凭据的资格检查
     */
    #[Get(path: '/{id:\d+}/vnc', name: 'admin.services.vnc.access')]
    public function access(Request $request, mixed $id): Response
    {
        $data = InstanceOperationValidator::validate($id);
        return $this->responses->success($request, $this->vnc->access($this->context->account($request), $data['id']))->withHeaders(self::HEADERS);
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 实例路径
     * @return Response 本次临时连接
     */
    #[Post(path: '/{id:\d+}/vnc', name: 'admin.services.vnc.open')]
    public function open(Request $request, mixed $id): Response
    {
        $data = InstanceOperationValidator::validate($id);
        return $this->responses->success($request, $this->vnc->open($this->context->account($request), $data['id']))->withHeaders(self::HEADERS);
    }

    private const array HEADERS = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer'];
}
