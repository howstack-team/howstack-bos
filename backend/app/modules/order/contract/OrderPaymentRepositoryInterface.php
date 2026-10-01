<?php

declare(strict_types=1);

namespace app\modules\order\contract;

use app\modules\order\domain\WalletPayableOrder;

/** 仅供付款用例使用的订单写仓储。 */
interface OrderPaymentRepositoryInterface
{
    /** 当前事务中锁定订单，未找到返回 null。 */
    public function lock(int $orderId): ?WalletPayableOrder;

    /** 每项单位数量、单位序号及总分摊金额必须完整。 */
    public function allocationIsComplete(int $orderId, int $amountMinor): bool;

    /** 确认已锁定的订单付款。 */
    public function markPaid(int $orderId, int $amountMinor, string $paidAt): void;
}
