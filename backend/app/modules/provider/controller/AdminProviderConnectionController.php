<?php

declare(strict_types=1);

namespace app\modules\provider\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\provider\application\ManageProviderConnectionsService;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post, Put, Patch};
use support\{Request, Response};
use support\validation\Validator;

/** 供应连接、可用适配插件及目录映射入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/provider-connections')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminProviderConnectionController
{
    public function __construct(private ManageProviderConnectionsService $service, private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    /** @param Request $request 请求 @return Response 连接列表 */
    #[Get(path: '', name: 'admin.provider-connections.index')]
    public function index(Request $request): Response { return $this->responses->success($request, $this->service->list($this->context->account($request))); }

    /** @param Request $request 请求 @return Response 已安装启用的适配器插件 */
    #[Get(path: '/adapters', name: 'admin.provider-connections.adapters')]
    public function adapters(Request $request): Response { return $this->responses->success($request, $this->service->adapters($this->context->account($request))); }

    /** @param Request $request 请求 @return Response 本地映射选项 */
    #[Get(path: '/references', name: 'admin.provider-connections.references')]
    public function references(Request $request): Response { return $this->responses->success($request, $this->service->references($this->context->account($request))); }

    /** @param Request $request 请求 @return Response 新连接 */
    #[Post(path: '', name: 'admin.provider-connections.store')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function store(Request $request): Response { return $this->responses->success($request, $this->service->save($this->context->account($request), null, $this->input($request)), httpStatus: 201); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 更新连接 */
    #[Put(path: '/{id:\d+}', name: 'admin.provider-connections.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, mixed $id): Response { return $this->responses->success($request, $this->service->save($this->context->account($request), $this->id($id), $this->input($request))); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 启停结果 */
    #[Patch(path: '/{id:\d+}/status', name: 'admin.provider-connections.status')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function status(Request $request, mixed $id): Response
    {
        $v = Validator::make($request->post(), ['revision' => 'required|integer|min:1', 'status' => 'required|integer|in:0,1'])->validate();
        return $this->responses->success($request, $this->service->status($this->context->account($request), $this->id($id), (int) $v['revision'], (int) $v['status']));
    }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 测试结果 */
    #[Post(path: '/{id:\d+}/test', name: 'admin.provider-connections.test')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function test(Request $request, mixed $id): Response { return $this->responses->success($request, $this->service->test($this->context->account($request), $this->id($id))); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 目录 */
    #[Post(path: '/{id:\d+}/catalog', name: 'admin.provider-connections.catalog')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function catalog(Request $request, mixed $id): Response
    {
        $v = Validator::make($request->post() + ['page' => 1, 'per_page' => 50], ['kind' => 'required|string|regex:/^[a-z][a-z0-9_]{0,23}$/',
            'page' => 'required|integer|min:1|max:10000', 'per_page' => 'required|integer|min:1|max:100'])->validate();
        $q = array_map('intval', array_intersect_key($v, array_flip(['page', 'per_page'])));
        return $this->responses->success($request, $this->service->catalog($this->context->account($request), $this->id($id), $v['kind'], $q));
    }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 映射 */
    #[Get(path: '/{id:\d+}/mappings', name: 'admin.provider-connections.mappings')]
    public function mappings(Request $request, mixed $id): Response { return $this->responses->success($request, $this->service->mappings($this->context->account($request), $this->id($id))); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 保存映射 */
    #[Post(path: '/{id:\d+}/mappings', name: 'admin.provider-connections.map')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function map(Request $request, mixed $id): Response
    {
        $v = Validator::make($request->post() + ['scope_zone_id' => 0], ['resource_kind' => 'required|string|regex:/^[a-z][a-z0-9_]{0,23}$/', 'local_id' => 'required|integer|min:1', 'external_id' => 'required|string|max:128', 'scope_zone_id' => 'integer|min:0'])->validate();
        $this->service->map($this->context->account($request), $this->id($id), $v['resource_kind'], (int) $v['local_id'], $v['external_id'], (int) $v['scope_zone_id']);
        return $this->responses->success($request);
    }

    /** @param Request $request 请求 @return array<string, mixed> 白名单连接字段 */
    private function input(Request $request): array
    {
        $v = Validator::make($request->post() + ['revision' => 0, 'clear_secrets' => []], ['name' => 'required|string|max:128',
            'adapter_id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/', 'configuration' => 'required|array|max:16',
            'status' => 'required|integer|in:0,1', 'revision' => 'required|integer|min:0', 'clear_secrets' => 'array|max:16', 'clear_secrets.*' => 'string|max:64'])->validate();
        return ['name' => trim($v['name']), 'adapter_id' => $v['adapter_id'], 'configuration' => $v['configuration'], 'status' => (int) $v['status'], 'revision' => (int) $v['revision'], 'clear_secrets' => $v['clear_secrets']];
    }

    /** @param mixed $id 标识 @return int 合法标识 */
    private function id(mixed $id): int { return (int) Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate()['id']; }
}
