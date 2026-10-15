<?php

declare(strict_types=1);

namespace app\modules\member\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\member\application\RealNameManagementService;
use app\modules\member\validation\MemberValidator;
use app\modules\member\validation\RealNameAuthenticationValidator;
use app\shared\http\ApiResponse;
use app\shared\validation\TableListQueryValidator;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Put;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

/** 提供运营后台实名认证记录与审核接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/real-name-authentications')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminRealNameAuthenticationController
{
    public function __construct(private RealNameManagementService $management, private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '', name: 'admin.real-name-authentications.index')]
    public function index(Request $request): Response
    {
        $query = RealNameAuthenticationValidator::page($request->get());
        return $this->responses->success($request, $this->management->paginate(
            $this->context->account($request), $query['keyword'], $query['type'], $query['status'], $query['page'], $query['page_size'],
            TableListQueryValidator::validate($request->get(), ['mobile' => 20, 'nickname' => 64, 'subject' => 128], ['submitted_at', 'reviewed_at']),
        ));
    }

    #[Get(path: '/{id:\d+}', name: 'admin.real-name-authentications.show')]
    public function show(Request $request, mixed $id): Response { return $this->responses->success($request, $this->management->detail($this->context->account($request), MemberValidator::id($id))); }

    #[Put(path: '/{id:\d+}/review', name: 'admin.real-name-authentications.review')]
    public function review(Request $request, mixed $id): Response
    {
        $input = RealNameAuthenticationValidator::review($request->post());
        return $this->responses->success($request, $this->management->review($this->context->account($request), MemberValidator::id($id), $input['decision'], $input['reason']), '审核完成');
    }
}
