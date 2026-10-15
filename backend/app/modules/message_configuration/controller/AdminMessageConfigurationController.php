<?php

declare(strict_types=1);

namespace app\modules\message_configuration\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\message_configuration\application\ManageMessageConfigurationsService;
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

/** 固定消息配置后台入口，不提供发送、自由新增消息类型或公开机器人代理。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/message-configurations')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminMessageConfigurationController
{
    public function __construct(
        private ManageMessageConfigurationsService $settings,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/{audience}', name: 'admin.message-configurations.index')]
    public function index(Request $request, string $audience): Response
    {
        return $this->responses->success($request, $this->settings->list($this->context->account($request), $audience, $request->get()));
    }

    #[Post(path: '/{audience}/batch', name: 'admin.message-configurations.batch')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function batch(Request $request, string $audience): Response
    {
        return $this->responses->success($request, $this->settings->batch($this->context->account($request), $audience, $request->post()));
    }

    #[Get(path: '/{audience}/{code:[a-z][a-z0-9_]{0,63}}', name: 'admin.message-configurations.show')]
    public function show(Request $request, string $audience, string $code): Response
    {
        return $this->responses->success($request, $this->settings->get($this->context->account($request), $audience, $code));
    }

    #[Put(path: '/{audience}/{code:[a-z][a-z0-9_]{0,63}}', name: 'admin.message-configurations.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, string $audience, string $code): Response
    {
        return $this->responses->success($request, $this->settings->save($this->context->account($request), $audience, $code, $request->post()));
    }
}
