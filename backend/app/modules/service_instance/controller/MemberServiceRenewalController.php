<?php

declare(strict_types=1);

namespace app\modules\service_instance\controller;

use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\MemberAuthMiddleware;
use app\modules\service_instance\application\RenewServiceService;
use app\modules\service_instance\validation\ServiceRenewalValidator;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Post};
use support\{Request, Response};

/** 续费以原服务为边界，所有写请求要求认证及同源校验。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/services')]
#[Middleware(MemberAuthMiddleware::class, \app\modules\member\middleware\MemberWriteOriginMiddleware::class)]
final readonly class MemberServiceRenewalController
{
    /** @param RenewServiceService $renewals 续费用例 @param MemberAuthenticationContext $context 认证 @param ApiResponse $responses 统一响应 */
    public function __construct(private RenewServiceService $renewals, private MemberAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    /** @param Request $request 请求 @param mixed $id 服务路径 @return Response 当前可用区周期或报价 */
    #[Post(path: '/{id:\d+}/renewal-quote', name: 'console.services.renewal-quote')]
    public function quote(Request $request, mixed $id): Response
    {
        $v = ServiceRenewalValidator::validate($id, $request->post());
        return $this->responses->success($request, $this->renewals->quote($this->context->account($request)->id, $v['id'], $v['months']))->withHeader('Cache-Control', 'no-store');
    }

    /** @param Request $request 请求 @param mixed $id 服务路径 @return Response 待付款续费订单 */
    #[Post(path: '/{id:\d+}/renewals', name: 'console.services.renewals.create')]
    public function create(Request $request, mixed $id): Response
    {
        $v = ServiceRenewalValidator::validate($id, $request->post(), true);
        return $this->responses->success($request, $this->renewals->create($this->context->account($request)->id, $v['id'], $v, 'member:' . $this->context->account($request)->id));
    }
}
