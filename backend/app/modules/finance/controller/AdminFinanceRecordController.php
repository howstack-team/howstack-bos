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
use app\shared\validation\TableListQueryValidator;

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
            array_merge($this->filters($request), TableListQueryValidator::validate($request->get(), [], match ($data['resource']) {
                'recharges', 'payments' => ['created_at', 'paid_at', 'amount_minor'],
                'transactions' => ['created_at', 'amount_minor', 'available_after_minor'],
                'refunds' => ['created_at', 'reviewed_at', 'completed_at', 'amount_minor'],
                'invoices' => ['created_at', 'reviewed_at', 'issued_at', 'amount_minor'],
                'withdrawals' => ['created_at', 'reviewed_at', 'payout_at', 'amount_minor'],
            })),
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
    private function filters(Request $request): array
    {
        $data = Validator::make(array_intersect_key($request->get(), array_flip(['number', 'member_id', 'created_from', 'created_to'])), [
            'number' => 'sometimes|nullable|string|max:48', 'member_id' => 'sometimes|nullable|integer|min:1',
            'created_from' => 'sometimes|nullable|date_format:Y-m-d',
            'created_to' => 'sometimes|nullable|date_format:Y-m-d' . ($request->get('created_from') ? '|after_or_equal:created_from' : ''),
        ])->validate();
        return array_filter($data, static fn ($value): bool => $value !== null && $value !== '');
    }
}
