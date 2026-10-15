<?php

declare(strict_types=1);

namespace app\modules\finance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\finance\application\ManageFinanceService;
use app\modules\finance\validation\FinanceInputValidator;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\RouteGroup;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\Request;
use support\Response;
use support\validation\Validator;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/finance')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminFinanceWorkflowController
{
    public function __construct(
        private ManageFinanceService $finance,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/{resource}/members', name: 'admin.finance.members')]
    public function members(Request $request, mixed $resource): Response
    {
        $resource = FinanceInputValidator::resource($resource);
        $data = Validator::make(['search' => $request->get('search', '')], ['search' => 'string|max:64'])->validate();
        return $this->responses->success($request, $this->finance->members(
            $this->context->account($request), $resource, trim($data['search']),
        ));
    }

    #[Get(path: '/{resource}/options', name: 'admin.finance.options')]
    public function options(Request $request, mixed $resource): Response
    {
        $data = Validator::make(['resource' => $resource, 'member_id' => $request->get('member_id')], [
            'resource' => 'required|string|in:refunds,invoices,withdrawals',
            'member_id' => 'required|integer|min:1',
        ])->validate();
        return $this->responses->success($request, $this->finance->options(
            $this->context->account($request), $data['resource'], (int) $data['member_id'],
        ));
    }

    #[Post(path: '/{resource}', name: 'admin.finance.create')]
    public function create(Request $request, mixed $resource): Response
    {
        $resource = FinanceInputValidator::resource($resource);
        return $this->responses->success($request, $this->finance->create(
            $this->context->account($request), $resource, FinanceInputValidator::create($resource, $request->post()),
        ));
    }

    #[Post(path: '/{resource}/{id:\d+}/{action}', name: 'admin.finance.action')]
    public function act(Request $request, mixed $resource, mixed $id, mixed $action): Response
    {
        $resource = FinanceInputValidator::resource($resource);
        $data = FinanceInputValidator::action($resource, $id, $action, $request->post());
        return $this->responses->success($request, $this->finance->act(
            $this->context->account($request), $resource, $data['id'], $data['action'], $data['input'],
        ));
    }
}
