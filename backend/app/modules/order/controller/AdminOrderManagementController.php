<?php

declare(strict_types=1);

namespace app\modules\order\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\order\application\ManageAdminOrdersService;
use app\modules\order\validation\OrderSelectionValidator;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};
use support\validation\Validator;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/orders')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminOrderManagementController
{
    public function __construct(private ManageAdminOrdersService $orders, private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '/members', name: 'admin.orders.members')]
    public function members(Request $request): Response
    {
        $data = Validator::make(['search' => $request->get('search','')], ['search' => 'string|max:64'])->validate();
        return $this->responses->success($request, $this->orders->members($this->context->account($request), trim($data['search'])));
    }
    #[Get(path: '/products', name: 'admin.orders.products')]
    public function products(Request $request): Response
    {
        return $this->responses->success($request, $this->orders->choices($this->context->account($request)));
    }
    #[Post(path: '/preview', name: 'admin.orders.preview')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function preview(Request $request): Response
    {
        $data = Validator::make($request->post(), ['offer_id' => 'required|integer|min:1', 'version_id' => 'required|integer|min:1', 'mode' => 'required|in:options,price'])->validate();
        return $this->responses->success($request, $this->orders->preview($this->context->account($request), (int) $data['offer_id'], (int) $data['version_id'], OrderSelectionValidator::clean($request->post('selection', [])), $data['mode'] === 'price'));
    }
    #[Post(path: '', name: 'admin.orders.create')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function create(Request $request): Response
    {
        $data = Validator::make($request->post() + ['remark' => ''], ['member_id' => 'required|integer|min:1', 'offer_id' => 'required|integer|min:1',
            'version_id' => 'required|integer|min:1', 'expected_amount_minor' => 'required|integer|min:1|max:100000000',
            'request_key' => 'required|string|regex:/^[A-Za-z0-9_-]{16,64}$/', 'remark' => 'string|max:255'])->validate();
        $input = array_intersect_key($data, array_flip(['member_id','offer_id','version_id','expected_amount_minor','request_key','remark']));
        foreach (['member_id','offer_id','version_id','expected_amount_minor'] as $field) $input[$field] = (int) $input[$field];
        $input['remark'] = trim($input['remark']);
        $input['selection'] = OrderSelectionValidator::clean($request->post('selection', []));
        return $this->responses->success($request, $this->orders->create($this->context->account($request), $input));
    }
    #[Post(path: '/{id:\d+}/{action}', name: 'admin.orders.action')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function act(Request $request, mixed $id, mixed $action): Response
    {
        $data = Validator::make(['id' => $id,'action' => $action], ['id' => 'required|integer|min:1','action' => 'required|in:pay,cancel,update'])->validate();
        $input = $data['action'] === 'update' ? Validator::make($request->post(), ['remark' => 'present|string|max:255','expected_remark' => 'present|string|max:255'])->validate() : [];
        $input = array_intersect_key($input, array_flip(['remark','expected_remark']));
        if (isset($input['remark'])) $input['remark'] = trim($input['remark']);
        return $this->responses->success($request, $this->orders->act($this->context->account($request), (int) $data['id'], $data['action'], $input));
    }
}
