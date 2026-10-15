<?php

declare(strict_types=1);

namespace app\modules\system_update\controller;

use app\exception\BusinessException;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\system_update\application\GetSystemVersionService;
use app\shared\http\ApiResponse;
use JsonException;
use Random\RandomException;
use RuntimeException;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\Request;
use support\Response;

/** 当前代码版本的后台只读 HTTP 入口。 */
#[DisableDefaultRoute]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class SystemVersionController
{
    /**
     * 初始化版本查询入口。
     * @param GetSystemVersionService    $versions  版本查询用例
     * @param AdminAuthenticationContext $context   请求身份上下文
     * @param ApiResponse                $responses 统一响应生成器
     */
    public function __construct(
        private GetSystemVersionService $versions,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    /**
     * 读取当前运行代码包的版本。
     * @param Request $request 当前请求
     * @return Response 统一版本信息响应
     * @throws AdminAuthenticationException 请求未认证时抛出
     * @throws BusinessException 缺少页面查看权限时抛出
     * @throws RuntimeException 本地版本配置无效时抛出
     * @throws JsonException 响应无法序列化时抛出
     * @throws RandomException 无法生成请求追踪标识时抛出
     */
    #[Get(path: '/api/admin/v1/system/version', name: 'admin.system-version.show')]
    public function show(Request $request): Response
    {
        return $this->responses->success($request, $this->versions->execute($this->context->account($request)));
    }
}
