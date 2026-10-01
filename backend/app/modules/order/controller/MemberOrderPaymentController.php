<?php

declare(strict_types=1);

namespace app\modules\order\controller;

use app\modules\order\application\PayOrderByWalletService;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\MemberAuthMiddleware;
use app\modules\member\middleware\MemberWriteOriginMiddleware;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\validation\Validator;

/** 保持原钱包付款 HTTP 契约，入口归订单模块。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberOrderPaymentController
{
    public function __construct(
        private PayOrderByWalletService $payments,
        private MemberAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Post(path: '/orders/{id:\d+}/pay-wallet', name: 'member.orders.pay-wallet')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function payOrder(Request $request, mixed $id): Response
    {
        $validated = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return $this->responses->success($request, [
            'available_minor' => $this->payments->pay($this->context->account($request)->id, (int) $validated['id']),
        ]);
    }
}
