<?php

declare(strict_types=1);
namespace app\modules\service_instance\controller;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\{MemberAuthMiddleware, MemberWriteOriginMiddleware};
use app\modules\service_instance\application\ViewMemberServicesService;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};
use support\validation\Validator;

/** 本人云服务器和限时密码 API，不向浏览器提供供应凭据、绑定或原始响应。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/services')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberServiceController
{
    /** @param ViewMemberServicesService $services 服务用例 @param MemberAuthenticationContext $context 认证身份 @param ApiResponse $responses 统一响应 */
    public function __construct(private ViewMemberServicesService $services, private MemberAuthenticationContext $context, private ApiResponse $responses) {}
    /** @param Request $request 请求 @return Response 本人服务列表 */
    #[Get(path: '', name: 'console.services.index')]
    public function index(Request $request): Response
    {
        $v = Validator::make(['page' => $request->get('page', 1), 'page_size' => $request->get('page_size', 20)], ['page' => 'required|integer|min:1|max:100000', 'page_size' => 'required|integer|min:1|max:100'])->validate();
        return $this->responses->success($request, $this->services->page($this->context->account($request)->id, (int) $v['page'], (int) $v['page_size']))->withHeader('Cache-Control', 'no-store');
    }
    /** @param Request $request 请求 @param mixed $id 路径标识 @return Response 本人服务详情 */
    #[Get(path: '/{id:\d+}', name: 'console.services.show')]
    public function show(Request $request, mixed $id): Response
    {
        $v = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return $this->responses->success($request, $this->services->detail($this->context->account($request)->id, (int) $v['id']))->withHeader('Cache-Control', 'no-store');
    }
    /** @param Request $request 请求 @param mixed $id 路径标识 @return Response 独立敏感响应，限同源本人操作 */
    #[Post(path: '/{id:\d+}/initial-password', name: 'console.services.initial-password')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function password(Request $request, mixed $id): Response
    {
        $v = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return $this->responses->success($request, $this->services->password($this->context->account($request)->id, (int) $v['id']))
            ->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
