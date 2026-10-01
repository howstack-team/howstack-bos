<?php

declare(strict_types=1);

namespace app\modules\system_settings\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\system_settings\application\ManageSystemSettingsService;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Put;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

/** 系统设置的版本化后台接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class SystemSettingsController
{
    /**
     * 初始化 HTTP 边界。
     * @param ManageSystemSettingsService $settings 设置用例
     * @param AdminAuthenticationContext  $context 请求身份上下文
     * @param ApiResponse                 $responses 统一响应
     */
    public function __construct(
        private ManageSystemSettingsService $settings,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    /**
     * 获取固定设置表单对应的分类。
     * @param Request $request 当前请求
     * @return Response 分类响应
     */
    #[Get(path: '/settings/categories', name: 'admin.settings.categories')]
    public function categories(Request $request): Response
    {
        return $this->responses->success($request, $this->settings->categories($this->context->account($request)));
    }

    /**
     * 获取单个分类的设置。
     * @param Request $request 当前请求
     * @param string  $category 分类路径参数
     * @return Response 类型化设置响应
     */
    #[Get(path: '/settings/{category}', name: 'admin.settings.show')]
    public function show(Request $request, string $category): Response
    {
        return $this->responses->success($request, $this->settings->get($this->context->account($request), $category));
    }

    /**
     * 按分类保存完整表单。
     * @param Request $request 当前请求
     * @param string  $category 分类路径参数
     * @return Response 已保存设置响应
     */
    #[Put(path: '/settings/{category}', name: 'admin.settings.update')]
    public function update(Request $request, string $category): Response
    {
        return $this->responses->success($request, $this->settings->save(
            $this->context->account($request), $category, $request->post(),
        ));
    }
}
