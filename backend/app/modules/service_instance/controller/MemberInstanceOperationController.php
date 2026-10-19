<?php

declare(strict_types=1);

namespace app\modules\service_instance\controller;

use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\MemberAuthMiddleware;
use app\modules\service_instance\application\OperateInstanceService;
use app\modules\service_instance\validation\InstanceOperationValidator;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};

/** 实例运维固定入口，提交仅表示持久队列受理。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/services')]
#[Middleware(MemberAuthMiddleware::class, \app\modules\member\middleware\MemberWriteOriginMiddleware::class)]
final readonly class MemberInstanceOperationController
{
    /**
     * @param OperateInstanceService $operations 运维
     * @param MemberAuthenticationContext $context 认证
     * @param ApiResponse $responses 统一响应
     */
    public function __construct(private OperateInstanceService $operations, private MemberAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 服务路径
     * @return Response 能力与最近任务
     */
    #[Get(path: '/{id:\d+}/operations', name: 'console.services.operations.index')]
    public function index(Request $request, mixed $id): Response
    {
        $data = InstanceOperationValidator::validate($id);
        return $this->responses->success($request, $this->operations->options($this->context->account($request)->id, $data['id']))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 服务路径
     * @return Response 已受理任务
     */
    #[Post(path: '/{id:\d+}/operations', name: 'console.services.operations.create')]
    public function create(Request $request, mixed $id): Response
    {
        $data = InstanceOperationValidator::validate($id, $request->post(), true);
        return $this->responses->success($request, $this->operations->submit($this->context->account($request)->id, $data['id'], $data, 'member:' . $this->context->account($request)->id))->withHeader('Cache-Control', 'no-store');
    }
}
