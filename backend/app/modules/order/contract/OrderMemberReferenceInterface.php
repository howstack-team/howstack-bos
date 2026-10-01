<?php

declare(strict_types=1);

namespace app\modules\order\contract;

/** 其他模块可查询订单引用，不取得订单内部模型。 */
interface OrderMemberReferenceInterface
{
    public function hasOrders(int $memberId): bool;
}
