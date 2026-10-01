<?php

declare(strict_types=1);

namespace app\modules\order\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\order\application\ViewAdminOrdersService;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\validation\Validator;

/** 运营后台订单与订单项只读接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminOrderController
{
    public function __construct(
        private ViewAdminOrdersService $orders,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/orders', name: 'admin.orders.index')]
    public function index(Request $request): Response
    {
        $query = Validator::make([
            'keyword' => $request->get('keyword', ''),
            'status' => $request->get('status'),
            'page' => $request->get('page', 1),
            'page_size' => $request->get('page_size', 10),
        ], [
            'keyword' => 'string|max:64',
            'status' => 'nullable|string|in:pending_payment,paid,fulfilling,completed,cancelled,failed,refunding,refunded',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|min:1|max:100',
        ])->validate();

        return $this->responses->success($request, $this->orders->paginate(
            $this->context->account($request), trim($query['keyword']), $query['status'],
            (int) $query['page'], (int) $query['page_size'],
        ));
    }

    #[Get(path: '/orders/{id:\d+}', name: 'admin.orders.show')]
    public function show(Request $request, mixed $id): Response
    {
        $validated = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();

        return $this->responses->success($request, $this->orders->detail(
            $this->context->account($request), (int) $validated['id'],
        ));
    }
}
