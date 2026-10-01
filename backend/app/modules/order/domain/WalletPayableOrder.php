<?php

declare(strict_types=1);

namespace app\modules\order\domain;

/** 锁定后用于付款校验的订单快照。 */
final readonly class WalletPayableOrder
{
    public function __construct(
        public int $id,
        public string $number,
        public int $memberId,
        public string $currency,
        public string $status,
        public string $paymentStatus,
        public int $payableMinor,
        public int $paidMinor,
    ) {
    }
}
