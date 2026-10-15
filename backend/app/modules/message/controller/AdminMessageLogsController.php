<?php

declare(strict_types=1);

namespace app\modules\message\controller;

use app\modules\message\application\ManageMessageLogsService;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/message-logs')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminMessageLogsController
{
    public function __construct(private ManageMessageLogsService $logs, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '', name: 'admin.message-logs.index')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->logs->list($this->context->account($request), $request->get()));
    }

    #[Get(path: '/{id:[1-9][0-9]*}', name: 'admin.message-logs.show')]
    public function show(Request $request, int $id): Response
    {
        return $this->responses->success($request, $this->logs->detail($this->context->account($request), $id));
    }

    #[Post(path: '/deliveries/{id:[1-9][0-9]*}/retry', name: 'admin.message-logs.retry')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function retry(Request $request, int $id): Response
    {
        $this->logs->retry($this->context->account($request), $id);
        return $this->responses->success($request, null);
    }
}
