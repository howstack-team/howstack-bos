<?php

declare(strict_types=1);

namespace app\modules\admin\operation\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\admin\operation\application\ListAdminOperationLogsService;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\validation\Validator;

/** 运营后台操作日志的摘要列表和详情接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class OperationLogsController
{
    public function __construct(
        private ListAdminOperationLogsService $logs,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/operation-logs', name: 'admin.operation-logs.index')]
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

        return $this->responses->success($request, $this->logs->paginate(
            $this->context->account($request),
            trim($query['keyword']),
            $query['status'] === null ? null : (int) $query['status'],
            (int) $query['page'],
            (int) $query['page_size'],
        ));
    }

    #[Get(path: '/operation-logs/{id:\d+}', name: 'admin.operation-logs.show')]
    public function show(Request $request, mixed $id): Response
    {
        $validated = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();

        return $this->responses->success($request, $this->logs->detail(
            $this->context->account($request), (int) $validated['id'],
        ));
    }
}
