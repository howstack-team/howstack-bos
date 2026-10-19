<?php

declare(strict_types=1);

namespace app\modules\order\controller;

use app\modules\order\application\CancelMemberOrderService;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\{MemberAuthMiddleware, MemberWriteOriginMiddleware};
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Post};
use support\{Request, Response};
use support\validation\Validator;

/** 本人待付订单取消入口，同源防护及对象归属同时校验。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/orders')]
#[Middleware(MemberAuthMiddleware::class, MemberWriteOriginMiddleware::class)]
final readonly class MemberOrderCancellationController
{
    /**
     * @param CancelMemberOrderService $orders 取消用例
     * @param MemberAuthenticationContext $context 认证
     * @param ApiResponse $responses 统一响应
     */
    public function __construct(private CancelMemberOrderService $orders, private MemberAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    /**
     * @param Request $request 请求
     * @param mixed $id 路径
     * @return Response 取消结果
     */
    #[Post(path: '/{id:\d+}/cancel', name: 'console.orders.cancel')]
    public function cancel(Request $request, mixed $id): Response
    {
        $data = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $this->orders->cancel($this->context->account($request)->id, (int) $data['id']);
        return $this->responses->success($request, null);
    }
}
