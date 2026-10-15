<?php

declare(strict_types=1);

namespace app\modules\channel\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\channel\application\ManageChannelsService;
use app\modules\channel\application\mail\SendMailService;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Patch;
use support\annotation\route\Post;
use support\annotation\route\Put;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

/** 通道配置固定HTTP入口；类别、身份与操作权限由宿主用例验证。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/channels')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminChannelController
{
    /**
     * @param ManageChannelsService $channels 配置用例
     * @param AdminAuthenticationContext $context 请求身份
     * @param ApiResponse $responses 统一响应
     * @param SendMailService $mail 邮件发送
     */
    public function __construct(
        private ManageChannelsService $channels,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
        private SendMailService $mail,
    ) {
    }

    /**
     * @param Request $request 请求
     * @param string $category 类别
     * @return Response 通道分页
     */
    #[Get(path: '/{category}', name: 'admin.channels.index')]
    public function index(Request $request, string $category): Response
    {
        return $this->responses->success($request,
            $this->channels->catalog($this->context->account($request), $category, $request->get()));
    }

    /**
     * @param Request $request 请求
     * @param string $category 类别
     * @return Response 已校验本地插件目录及可用状态
     */
    #[Get(path: '/{category}/platforms', name: 'admin.channels.platforms')]
    public function platforms(Request $request, string $category): Response
    {
        return $this->responses->success($request,
            $this->channels->platforms($this->context->account($request), $category));
    }

    /**
     * @param Request $request 请求
     * @param string $category 类别
     * @param mixed $id 标识
     * @return Response 脱敏详情
     */
    #[Get(path: '/{category}/{id:\d+}', name: 'admin.channels.show')]
    public function show(Request $request, string $category, mixed $id): Response
    {
        return $this->responses->success($request,
            $this->channels->detail($this->context->account($request), $category, $id));
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 邮件通道
     * @return Response 测试发送结果
     */
    #[Post(path: '/mail/{id:\d+}/test', name: 'admin.channels.mail.test')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function testMail(Request $request, mixed $id): Response
    {
        return $this->responses->success($request,
            $this->mail->test($this->context->account($request), $id, $request->post()));
    }

    /**
     * @param Request $request 请求
     * @param string $category 类别
     * @param string $pluginId 已注册插件
     * @return Response 首次配置结果
     */
    #[Put(path: '/{category}/platforms/{pluginId:[a-z][a-z0-9_]{0,63}}', name: 'admin.channels.configure')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function configure(Request $request, string $category, string $pluginId): Response
    {
        return $this->responses->success($request,
            $this->channels->configure($this->context->account($request), $category, $pluginId, $request->post()));
    }

    /**
     * @param Request $request 请求
     * @param string $category 类别
     * @param mixed $id 标识
     * @return Response 保存后的通道
     */
    #[Put(path: '/{category}/{id:\d+}', name: 'admin.channels.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, string $category, mixed $id): Response
    {
        return $this->responses->success($request,
            $this->channels->updateConfiguration($this->context->account($request), $category, $id, $request->post()));
    }

    /**
     * @param Request $request 请求
     * @param string $category 类别
     * @param mixed $id 标识
     * @return Response 启停结果
     */
    #[Patch(path: '/{category}/{id:\d+}/status', name: 'admin.channels.status')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function status(Request $request, string $category, mixed $id): Response
    {
        return $this->responses->success($request,
            $this->channels->status($this->context->account($request), $category, $id, $request->post()));
    }

}
