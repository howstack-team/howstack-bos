<?php

declare(strict_types=1);

namespace app\modules\order\controller;

use app\modules\order\application\MemberPurchaseOrderService;
use app\modules\order\validation\OrderSelectionValidator;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\{MemberAuthMiddleware, MemberWriteOriginMiddleware};
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};
use support\validation\Validator;

#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/orders')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberPurchaseOrderController
{
    public function __construct(private MemberPurchaseOrderService $orders, private MemberAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '', name: 'member.orders.list')]
    public function index(Request $request): Response
    {
        $v = Validator::make(['page' => $request->get('page', 1), 'page_size' => $request->get('page_size', 20)],
            ['page' => 'required|integer|min:1|max:100000', 'page_size' => 'required|integer|min:1|max:100'])->validate();
        return $this->responses->success($request, $this->orders->page($this->context->account($request)->id, (int) $v['page'], (int) $v['page_size']));
    }

    #[Get(path: '/{id:\d+}', name: 'member.orders.detail')]
    public function detail(Request $request, mixed $id): Response
    {
        $v = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return $this->responses->success($request, $this->orders->detail($this->context->account($request)->id, (int) $v['id']))->withHeader('Cache-Control', 'no-store');
    }

    #[Post(path: '', name: 'member.orders.create')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function create(Request $request): Response
    {
        $v = Validator::make($request->post(), ['offer_id' => 'required|integer|min:1', 'version_id' => 'required|integer|min:1',
            'expected_amount_minor' => 'required|integer|min:1|max:100000000', 'request_key' => 'required|string|regex:/^[A-Za-z0-9_-]{16,64}$/'])->validate();
        $input = array_intersect_key($v, array_flip(['offer_id', 'version_id', 'expected_amount_minor', 'request_key']));
        foreach (['offer_id', 'version_id', 'expected_amount_minor'] as $key) $input[$key] = (int) $input[$key];
        $input['selection'] = OrderSelectionValidator::clean($request->post('selection', []));
        $input['login_password'] = Validator::make($request->post(), ['login_password' => 'sometimes|string|min:8|max:72'])->validate()['login_password'] ?? null;
        return $this->responses->success($request, $this->orders->create($this->context->account($request)->id, $input))->withHeader('Cache-Control', 'no-store');
    }
}
