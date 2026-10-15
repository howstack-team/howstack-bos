<?php

declare(strict_types=1);

namespace app\modules\order\contract;

/** 订单向财务开放的实例金额契约；所有写方法要求调用方持有默认事务。 */
interface OrderFinanceRepositoryInterface
{
    /** @return array<string, mixed> 锁定订单与全部实例，按订单、实例 ID 顺序 */
    public function lock(int $orderId): array;

    /** @return int 根据实例定位订单，不产生写入 */
    public function orderId(int $unitId): int;

    public function reserve(int $unitId, int $amount): void;

    public function release(int $unitId, int $amount): void;

    public function complete(int $orderId, int $unitId, int $amount): void;
}
