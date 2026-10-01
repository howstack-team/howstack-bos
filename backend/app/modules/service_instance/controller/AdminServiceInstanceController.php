<?php

declare(strict_types=1);

namespace app\modules\service_instance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\service_instance\application\ViewServiceInstancesService;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\validation\Validator;

/** 运营后台已创建服务实例的只读入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminServiceInstanceController
{
    public function __construct(
        private ViewServiceInstancesService $instances,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/service-instances', name: 'admin.service-instances.index')]
    public function index(Request $request): Response
    {
        $query = Validator::make([
            'product_type' => $request->get('product_type'),
            'keyword' => $request->get('keyword', ''),
            'status' => $request->get('status'),
            'page' => $request->get('page', 1),
            'page_size' => $request->get('page_size', 10),
        ], [
            'product_type' => 'required|string|in:cloud_server,physical_server,server_colocation',
            'keyword' => 'string|max:64',
            'status' => 'nullable|string|in:provisioning,active,suspended,expired,terminated,failed',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|min:1|max:100',
        ])->validate();

        return $this->responses->success($request, $this->instances->paginate(
            $this->context->account($request), $query['product_type'], trim($query['keyword']),
            $query['status'], (int) $query['page'], (int) $query['page_size'],
        ));
    }

    #[Get(path: '/service-instances/{id:\d+}', name: 'admin.service-instances.show')]
    public function show(Request $request, mixed $id): Response
    {
        $validated = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();

        return $this->responses->success($request, $this->instances->detail(
            $this->context->account($request), (int) $validated['id'],
        ));
    }
}
