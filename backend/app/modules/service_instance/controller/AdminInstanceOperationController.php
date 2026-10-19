<?php

declare(strict_types=1);

namespace app\modules\service_instance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\service_instance\application\ManageInstanceOperationsService;
use app\modules\service_instance\validation\InstanceOperationValidator;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};

/** 实例运维固定入口，提交仅表示持久队列受理。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/service-instances')]
#[Middleware(AdminAuthMiddleware::class, \app\shared\http\middleware\SameOriginWriteMiddleware::class)]
final readonly class AdminInstanceOperationController
{
    /**
     * @param ManageInstanceOperationsService $operations 运维
     * @param AdminAuthenticationContext $context 认证
     * @param ApiResponse $responses 统一响应
     */
    public function __construct(private ManageInstanceOperationsService $operations, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 服务路径
     * @return Response 能力与最近任务
     */
    #[Get(path: '/{id:\d+}/operations', name: 'admin.services.operations.index')]
    public function index(Request $request, mixed $id): Response
    {
        $data = InstanceOperationValidator::validate($id);
        return $this->responses->success($request, $this->operations->options($this->context->account($request), $data['id']))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 服务路径
     * @return Response 已受理任务
     */
    #[Post(path: '/{id:\d+}/operations', name: 'admin.services.operations.create')]
    public function create(Request $request, mixed $id): Response
    {
        $data = InstanceOperationValidator::validate($id, $request->post(), true);
        return $this->responses->success($request, $this->operations->submit($this->context->account($request), $data['id'], $data))->withHeader('Cache-Control', 'no-store');
    }
}
