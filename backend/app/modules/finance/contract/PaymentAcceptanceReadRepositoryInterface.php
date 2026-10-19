<?php

declare(strict_types=1);

namespace app\modules\finance\contract;

/** 支付验收只读证据，不提供任何补账、改状态或发起支付能力。 */
interface PaymentAcceptanceReadRepositoryInterface
{
    /**
     * 在同一读取事务中获取本地收款及来源证据。
     * @param int $paymentId 后台支付记录内部ID
     * @return array<string,mixed>|null 白名单原始证据；仅供本模块核对
     */
    public function evidence(int $paymentId): ?array;
}
