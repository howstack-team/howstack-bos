<?php

declare(strict_types=1);

namespace app\modules\website\controller;

use app\modules\website\application\ConsoleThemeRuntimeService;
use app\shared\http\ApiResponse;
use support\annotation\route\{DisableDefaultRoute, Get};
use support\Request;
use support\Response;

/** 统一会员中心主题的公开引导接口，不包含登录身份或秘密配置。 */
#[DisableDefaultRoute]
final readonly class ConsoleThemeController
{
    public function __construct(private ConsoleThemeRuntimeService $runtime, private ApiResponse $responses)
    {
    }

    #[Get(path: '/api/console/v1/bootstrap', name: 'console.theme.bootstrap')]
    public function bootstrap(Request $request): Response
    {
        return $this->responses->success($request, $this->runtime->bootstrap((string) $request->cookie('console_presentation', 'auto'), (string) $request->header('user-agent', '')))->withHeaders(['Cache-Control' => 'no-store']);
    }
}
