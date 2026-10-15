<?php

declare(strict_types=1);

namespace app\modules\compute\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\compute\application\ManageComputeInventoryService;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Put};
use support\{Request, Response};
use support\validation\Validator;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/cloud-servers/inventory')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminComputeInventoryController
{
    public function __construct(private ManageComputeInventoryService $service, private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '', name: 'admin.cloud-inventory.index')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->service->all($this->context->account($request)));
    }

    #[Put(path: '', name: 'admin.cloud-inventory.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request): Response
    {
        $data = Validator::make($request->post(), ['zone_id' => 'required|integer|min:1', 'flavor_id' => 'required|integer|min:1', 'capacity' => 'present|nullable|integer|min:0|max:100000000', 'revision' => 'required|integer|min:0'])->validate();
        foreach (['zone_id', 'flavor_id', 'revision'] as $key) $data[$key] = (int) $data[$key];
        $data['capacity'] = $data['capacity'] === null ? null : (int) $data['capacity'];
        return $this->responses->success($request, $this->service->configure($this->context->account($request), $data));
    }
}
