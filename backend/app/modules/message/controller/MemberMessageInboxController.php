<?php

declare(strict_types=1);

namespace app\modules\message\controller;

use app\modules\message\application\MessageInboxService;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\MemberAuthMiddleware;
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
#[RouteGroup('/api/console/v1/messages')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberMessageInboxController
{
    public function __construct(private MessageInboxService $inbox, private MemberAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '', name: 'customer.messages.index')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->inbox->list('customer', $this->context->account($request)->id, $request->get()));
    }

    #[Get(path: '/summary', name: 'customer.messages.summary')]
    public function summary(Request $request): Response
    {
        return $this->responses->success($request, $this->inbox->summary('customer', $this->context->account($request)->id));
    }

    #[Post(path: '/read-all', name: 'customer.messages.read-all')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function readAll(Request $request): Response
    {
        $this->inbox->read('customer', $this->context->account($request)->id, null);
        return $this->responses->success($request, null);
    }

    #[Post(path: '/{id:[1-9][0-9]*}/read', name: 'customer.messages.read')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function read(Request $request, int $id): Response
    {
        $this->inbox->read('customer', $this->context->account($request)->id, $id);
        return $this->responses->success($request, null);
    }
}
