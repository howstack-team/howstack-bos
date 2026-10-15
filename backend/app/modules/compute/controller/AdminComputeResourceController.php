<?php

declare(strict_types=1);

namespace app\modules\compute\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\compute\application\ManageComputeResourcesService;
use app\modules\compute\validation\ComputeResourceValidator;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post, Put};
use support\{Request, Response};
use support\validation\Validator;

/** 云服务器专属配置资源入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/compute-resources')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminComputeResourceController
{
    public function __construct(private ManageComputeResourcesService $service, private ComputeResourceValidator $validator,
        private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    /** @param Request $request 请求 @return Response 资源目录 */
    #[Get(path: '', name: 'admin.compute-resources.index')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->service->all($this->context->account($request)));
    }

    /** @param Request $request 请求 @param string $kind 资源类型 @return Response 保存结果 */
    #[Post(path: '/{kind}', name: 'admin.compute-resources.store')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function store(Request $request, string $kind): Response
    {
        return $this->responses->success($request, $this->service->save($this->context->account($request), $kind, null, $this->validator->validate($kind, $request->post())), httpStatus: 201);
    }

    /** @param Request $request 请求 @param string $kind 资源类型 @param mixed $id 标识 @return Response 保存结果 */
    #[Put(path: '/{kind}/{id:\d+}', name: 'admin.compute-resources.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, string $kind, mixed $id): Response
    {
        $value = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return $this->responses->success($request, $this->service->save($this->context->account($request), $kind, (int) $value['id'], $this->validator->validate($kind, $request->post())));
    }
}
