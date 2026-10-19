<?php

declare(strict_types=1);

namespace app\modules\service_instance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\service_instance\application\ManageServiceRenewalService;
use app\modules\service_instance\validation\ServiceRenewalValidator;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Post};
use support\{Request, Response};

/** 续费以原服务为边界，所有写请求要求认证及同源校验。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/service-instances')]
#[Middleware(AdminAuthMiddleware::class, \app\shared\http\middleware\SameOriginWriteMiddleware::class)]
final readonly class AdminServiceRenewalController
{
    /** @param ManageServiceRenewalService $renewals 续费用例 @param AdminAuthenticationContext $context 认证 @param ApiResponse $responses 统一响应 */
    public function __construct(private ManageServiceRenewalService $renewals, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    /** @param Request $request 请求 @param mixed $id 服务路径 @return Response 当前可用区周期或报价 */
    #[Post(path: '/{id:\d+}/renewal-quote', name: 'admin.services.renewal-quote')]
    public function quote(Request $request, mixed $id): Response
    {
        $v = ServiceRenewalValidator::validate($id, $request->post());
        return $this->responses->success($request, $this->renewals->quote($this->context->account($request), $v['id'], $v['months']))->withHeader('Cache-Control', 'no-store');
    }

    /** @param Request $request 请求 @param mixed $id 服务路径 @return Response 待付款续费订单 */
    #[Post(path: '/{id:\d+}/renewals', name: 'admin.services.renewals.create')]
    public function create(Request $request, mixed $id): Response
    {
        $v = ServiceRenewalValidator::validate($id, $request->post(), true);
        return $this->responses->success($request, $this->renewals->create($this->context->account($request), $v['id'], $v));
    }

    /** @param Request $request 请求 @param mixed $id 服务路径 @return Response 已重新排队 */
    #[Post(path: '/{id:\d+}/retry-lifecycle', name: 'admin.services.retry-lifecycle')]
    public function retry(Request $request, mixed $id): Response
    {
        $v = ServiceRenewalValidator::validate($id, ['months' => 0]);
        $this->renewals->retry($this->context->account($request), $v['id']);
        return $this->responses->success($request, null);
    }
}
