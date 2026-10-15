<?php

declare(strict_types=1);

namespace app\modules\official_platform\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\official_platform\application\ManageOfficialConnectionService;
use app\modules\licensing\application\ManageLicenseService;
use app\shared\http\{ApiResponse, middleware\SameOriginWriteMiddleware};
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Post, RouteGroup};
use support\{Request, Response};
use support\validation\Validator;

/** 固定的官网授权码激活入口，不接收官网会员密码。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/official-platform')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminOfficialPlatformController
{
    /**
     * @param ManageOfficialConnectionService $connections 安装连接
     * @param ManageLicenseService $licenses 授权
     * @param AdminAuthenticationContext $context 身份
     * @param ApiResponse $responses 响应
     */
    public function __construct(
        private ManageOfficialConnectionService $connections,
        private ManageLicenseService $licenses,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    /**
     * @param Request $request 请求
     * @return Response 白名单本地状态
     */
    #[Get(path: '/connection')]
    public function connection(Request $request): Response
    {
        return $this->responses->success($request, $this->connections->status($this->context->account($request)));
    }

    /** @param Request $request 请求 @return Response 激活后已验证的本机授权 */
    #[Post(path: '/license/activate')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function activate(Request $request): Response
    {
        $input = Validator::make($request->post(), ['activation_code' => ['required', 'string', 'regex:/^HS-(?:[A-Fa-f0-9]{8}-){5}[A-Fa-f0-9]{8}$/D']], [], ['activation_code' => '授权码'])->validate();
        $actor = $this->context->account($request);
        $this->connections->activate($actor, $input['activation_code']);
        return $this->responses->success($request, $this->licenses->refresh($actor));
    }

    /**
     * @param Request $request 请求
     * @return Response 实时连接投影
     */
    #[Post(path: '/connection/refresh')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function refresh(Request $request): Response
    {
        return $this->responses->success($request, $this->connections->refresh($this->context->account($request)));
    }

    /**
     * @param Request $request 请求
     * @return Response 已撤销连接
     */
    #[Post(path: '/connection/disconnect')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function disconnect(Request $request): Response
    {
        $actor = $this->context->account($request);
        $this->connections->disconnect($actor);
        return $this->responses->success($request, $this->licenses->refresh($actor));
    }

    /**
     * @param Request $request 请求
     * @return Response 商业期限与默认社区版
     */
    #[Get(path: '/license')]
    public function license(Request $request): Response
    {
        return $this->responses->success($request, $this->licenses->status($this->context->account($request)))
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** @return Response 当前管理员的最小能力投影，不公开许可证与连接信息 */
    #[Get(path: '/capabilities', name: 'admin.official-platform.capabilities')]
    public function capabilities(Request $request): Response
    {
        return $this->responses->success($request, $this->licenses->capabilities($this->context->account($request)))
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * @param Request $request 请求
     * @return Response 已验签商业证书投影
     */
    #[Post(path: '/license/refresh')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function refreshLicense(Request $request): Response
    {
        return $this->responses->success($request, $this->licenses->refresh($this->context->account($request)));
    }
}
