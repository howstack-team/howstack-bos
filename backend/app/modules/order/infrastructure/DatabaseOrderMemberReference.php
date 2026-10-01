<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\modules\order\contract\OrderMemberReferenceInterface;
use app\modules\order\infrastructure\model\OrderModel;

final class DatabaseOrderMemberReference implements OrderMemberReferenceInterface
{
    public function hasOrders(int $memberId): bool
    {
        return OrderModel::query()->where('member_id', $memberId)->exists();
    }
}
