<?php

declare(strict_types=1);

namespace app\modules\admin\auth\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\application\ListAdminLoginLogsService;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\validation\Validator;

/** 运营后台登录审计记录查询接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class LoginLogsController
{
    public function __construct(
        private ListAdminLoginLogsService $logs,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/login-logs', name: 'admin.login-logs.index')]
    public function index(Request $request): Response
    {
        $query = Validator::make([
            'keyword' => $request->get('keyword', ''),
            'status' => $request->get('status'),
            'page' => $request->get('page', 1),
            'page_size' => $request->get('page_size', 20),
        ], [
            'keyword' => 'string|max:64',
            'status' => 'nullable|integer|in:0,1',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|min:1|max:100',
        ])->validate();

        return $this->responses->success($request, $this->logs->execute(
            $this->context->account($request),
            trim($query['keyword']),
            $query['status'] === null ? null : (int) $query['status'],
            (int) $query['page'],
            (int) $query['page_size'],
        ));
    }
}
