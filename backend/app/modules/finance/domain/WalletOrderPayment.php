<?php

declare(strict_types=1);

namespace app\modules\finance\domain;

/** 订单公开传递的付款指令，不携带订单模型或供应配置。 */
final readonly class WalletOrderPayment
{
    public function __construct(
        public int $orderId,
        public string $orderNo,
        public int $memberId,
        public int $amountMinor,
        public string $currency,
    ) {
    }
}
