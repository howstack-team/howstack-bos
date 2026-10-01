<?php

declare(strict_types=1);

namespace app\modules\finance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\finance\application\ViewFinanceRecordsService;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\validation\Validator;

/** 运营后台财务单据只读入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/finance')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminFinanceRecordController
{
    public function __construct(
        private ViewFinanceRecordsService $records,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/{resource}', name: 'admin.finance.index')]
    public function index(Request $request, mixed $resource): Response
    {
        $data = Validator::make([
            'resource' => $resource, 'page' => $request->get('page', 1),
            'page_size' => $request->get('page_size', 10), 'status' => $request->get('status'),
        ], [
            'resource' => 'required|string|in:recharges,payments,transactions,refunds,invoices,withdrawals',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|min:1|max:100',
            'status' => 'nullable|string|max:24',
        ])->validate();

        return $this->responses->success($request, $this->records->paginate(
            $this->context->account($request), $data['resource'], (int) $data['page'],
            (int) $data['page_size'], $data['status'],
        ));
    }

    #[Get(path: '/{resource}/{id:\d+}', name: 'admin.finance.show')]
    public function show(Request $request, mixed $resource, mixed $id): Response
    {
        $data = Validator::make(['resource' => $resource, 'id' => $id], [
            'resource' => 'required|string|in:recharges,payments,transactions,refunds,invoices,withdrawals',
            'id' => 'required|integer|min:1',
        ])->validate();

        return $this->responses->success($request, $this->records->detail(
            $this->context->account($request), $data['resource'], (int) $data['id'],
        ));
    }
}
