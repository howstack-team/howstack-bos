<?php

declare(strict_types=1);

namespace app\modules\message\controller;

use app\modules\message\application\MessageInboxService;
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
#[RouteGroup('/api/admin/v1/messages')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminMessageInboxController
{
    public function __construct(private MessageInboxService $inbox, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '', name: 'admin.messages.index')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->inbox->list('admin', $this->context->account($request)->id, $request->get()));
    }

    #[Get(path: '/summary', name: 'admin.messages.summary')]
    public function summary(Request $request): Response
    {
        return $this->responses->success($request, $this->inbox->summary('admin', $this->context->account($request)->id));
    }

    #[Post(path: '/read-all', name: 'admin.messages.read-all')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function readAll(Request $request): Response
    {
        $this->inbox->read('admin', $this->context->account($request)->id, null);
        return $this->responses->success($request, null);
    }

    #[Post(path: '/{id:[1-9][0-9]*}/read', name: 'admin.messages.read')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function read(Request $request, int $id): Response
    {
        $this->inbox->read('admin', $this->context->account($request)->id, $id);
        return $this->responses->success($request, null);
    }
}
