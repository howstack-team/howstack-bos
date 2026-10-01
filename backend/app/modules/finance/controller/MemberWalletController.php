<?php

declare(strict_types=1);

namespace app\modules\finance\controller;

use app\modules\finance\application\WalletLedgerService;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\MemberAuthMiddleware;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

/** 会员仅能读取自己的余额、支付自己的待付款订单。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberWalletController
{
    public function __construct(
        private WalletLedgerService $wallet,
        private MemberAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Get(path: '/wallet', name: 'member.wallet.show')]
    public function show(Request $request): Response
    {
        return $this->responses->success($request, $this->wallet->balance($this->context->account($request)->id));
    }

}
