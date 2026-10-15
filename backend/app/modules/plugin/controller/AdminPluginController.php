<?php

declare(strict_types=1);

namespace app\modules\plugin\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\plugin\application\ManagePluginsService;
use app\shared\contract\{ClientIpResolverInterface, IpLocationResolverInterface};
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};
use support\validation\Validator;

/** 核心固定插件管理接口；没有上传或动态控制器入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/business-plugins')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminPluginController
{
    /**
     * 接收核心用例与统一请求能力。
     * @param ManagePluginsService $service 管理用例
     * @param AdminAuthenticationContext $context 已认证身份
     * @param ApiResponse $responses 统一响应
     * @param ClientIpResolverInterface $ips 可信代理 IP 解析
     * @param IpLocationResolverInterface $locations IP 地点快照
     */
    public function __construct(
        private ManagePluginsService $service,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
        private ClientIpResolverInterface $ips,
        private IpLocationResolverInterface $locations,
    ) {}

    /**
     * 查询插件状态及非敏感配置声明。
     * @param Request $request 当前请求
     * @return Response 白名单结果
     */
    #[Get(path: '', name: 'admin.business-plugins.index')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->service->list($this->context->account($request)));
    }

    /**
     * 生命周期动作白名单，不把路径动作当成方法名执行。
     * @param Request $request 当前请求
     * @param mixed $id 插件标识
     * @return Response 新状态
     */
    #[Post(path: '/{id:[a-z][a-z0-9_]*}/lifecycle', name: 'admin.business-plugins.lifecycle')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function lifecycle(Request $request, mixed $id): Response
    {
        $values = Validator::make(array_replace($request->post(), ['id' => $id]), [
            'id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/',
            'action' => 'required|in:install,enable,drain,disable,upgrade,uninstall',
            'revision' => 'required|integer|min:0',
        ])->validate();
        $ip = $this->ips->resolve($request);
        return $this->responses->success($request, $this->service->transition(
            $this->context->account($request), $values['id'], $values['action'], (int) $values['revision'],
            ['client_ip' => $ip, 'client_ip_location' => $this->locations->resolve($ip)],
        ));
    }
}
