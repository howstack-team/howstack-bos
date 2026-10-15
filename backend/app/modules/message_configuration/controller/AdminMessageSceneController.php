<?php

declare(strict_types=1);

namespace app\modules\message_configuration\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\message_configuration\application\ManageMessageScenesService;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Put;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/message-scenes')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminMessageSceneController
{
    public function __construct(private ManageMessageScenesService $scenes, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '', name: 'admin.message-scenes.index')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->scenes->list($this->context->account($request), $request->get()));
    }

    #[Get(path: '/{code:[a-z][a-z0-9_]{0,63}}', name: 'admin.message-scenes.show')]
    public function show(Request $request, string $code): Response
    {
        return $this->responses->success($request, $this->scenes->get($this->context->account($request), $code));
    }

    #[Put(path: '/{code:[a-z][a-z0-9_]{0,63}}', name: 'admin.message-scenes.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, string $code): Response
    {
        return $this->responses->success($request, $this->scenes->save($this->context->account($request), $code, $request->post()));
    }

    #[Post(path: '/batch', name: 'admin.message-scenes.batch')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function batch(Request $request): Response
    {
        return $this->responses->success($request, $this->scenes->batch($this->context->account($request), $request->post()));
    }
}
