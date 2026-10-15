<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\application_center\application\ManageApplicationCenterService;
use app\modules\software_maintenance\application\ManageSoftwareUpdatesService;
use app\shared\contract\{ClientIpResolverInterface, IpLocationResolverInterface};
use app\shared\http\{ApiResponse, middleware\SameOriginWriteMiddleware};
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Post};
use support\{Request, Response};
use support\validation\Validator;

/** 在线更新和商店HTTP仅受理固定目标；安装由独立进程执行。 */
#[DisableDefaultRoute]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminSoftwareMaintenanceController
{
    /**
     * @param ManageSoftwareUpdatesService $updates 更新
     * @param ManageApplicationCenterService $applications 应用
     * @param AdminAuthenticationContext $context 身份
     * @param ApiResponse $responses 响应
     * @param ClientIpResolverInterface $ips IP
     * @param IpLocationResolverInterface $locations 地点
     */
    public function __construct(
        private ManageSoftwareUpdatesService $updates,
        private ManageApplicationCenterService $applications,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
        private ClientIpResolverInterface $ips,
        private IpLocationResolverInterface $locations,
    ) {
    }

    /**
     * @param Request $request 请求
     * @return Response 更新状态
     */
    #[Get(path: '/api/admin/v1/system-updates')]
    public function updates(Request $request): Response
    {
        return $this->responses->success($request, $this->updates->status($this->context->account($request)));
    }

    /** @return Response 本机缓存的更新提醒，读取不触发官网请求 */
    #[Get(path: '/api/admin/v1/system-updates/notice')]
    public function notice(Request $request): Response
    {
        return $this->responses->success($request, $this->updates->notice($this->context->account($request)));
    }

    /**
     * @param Request $request 请求
     * @return Response 经过签名验证的版本检查
     */
    #[Post(path: '/api/admin/v1/system-updates/check')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function check(Request $request): Response
    {
        return $this->responses->success($request, $this->updates->check($this->context->account($request)));
    }

    /**
     * @param Request $request 请求
     * @return Response 更新任务
     */
    #[Post(path: '/api/admin/v1/system-updates/start')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function start(Request $request): Response
    {
        $values = $this->target($request, ['target_version' => 'required|string|regex:/^\d+\.\d+\.\d+$/']);
        return $this->responses->success($request, $this->updates->start($this->context->account($request),
            $values['request_id'], $values['sha256'], $this->origin($request), $values['target_version']));
    }

    /**
     * @param Request $request 请求
     * @return Response 本实例权益和任务
     */
    #[Get(path: '/api/admin/v1/application-center/owned')]
    public function owned(Request $request): Response
    {
        return $this->responses->success($request, $this->applications->owned($this->context->account($request)));
    }

    /**
     * @param Request $request 请求
     * @return Response 无令牌的官方应用商店超链接
     */
    #[Get(path: '/api/admin/v1/application-center/store-link')]
    public function storeLink(Request $request): Response
    {
        return $this->responses->success($request, $this->applications->storeLink($this->context->account($request)));
    }

    /**
     * @param Request $request 请求
     * @return Response 保留配置及历史数据的卸载结果
     */
    #[Post(path: '/api/admin/v1/application-center/uninstall')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function uninstall(Request $request): Response
    {
        $values = Validator::make($request->post(), [
            'code' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/',
            'revision' => 'required|integer|min:0',
        ])->validate();

        return $this->responses->success($request, $this->applications->uninstall(
            $this->context->account($request), $values['code'], (int) $values['revision'], $this->origin($request),
        ));
    }

    /**
     * @param Request $request 请求
     * @return Response 安装确认资料
     */
    #[Post(path: '/api/admin/v1/application-center/prepare')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function prepare(Request $request): Response
    {
        $data = Validator::make($request->post(), ['version_id' => 'required|integer|min:1'])->validate();
        return $this->responses->success($request, $this->applications->prepare($this->context->account($request), (int) $data['version_id']));
    }

    /**
     * @param Request $request 请求
     * @return Response 本机应用任务
     */
    #[Post(path: '/api/admin/v1/application-center/install')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function install(Request $request): Response
    {
        $data = $this->target($request, ['version_id' => 'required|integer|min:1', 'action' => 'required|in:install,update']);
        return $this->responses->success($request, $this->applications->start($this->context->account($request),
            (int) $data['version_id'], $data['request_id'], $data['sha256'], $data['action'], $this->origin($request)));
    }

    /**
     * @param Request $request 请求
     * @param array<string,string> $extra 固定校验
     * @return array<string,mixed> UUID和摘要
     */
    private function target(Request $request, array $extra = []): array
    {
        $values = Validator::make($request->post(), ['request_id' => 'required|uuid',
            'sha256' => 'required|string|regex:/^[a-f0-9]{64}$/'] + $extra)->validate();
        $values['request_id'] = strtolower($values['request_id']);

        return $values;
    }

    /**
     * @param Request $request 请求
     * @return array<string,string> 可信代理与地点快照
     */
    private function origin(Request $request): array
    {
        $ip = $this->ips->resolve($request);
        return ['client_ip' => $ip, 'client_ip_location' => $this->locations->resolve($ip)];
    }
}
