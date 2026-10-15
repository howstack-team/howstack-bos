<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 在订单默认事务内办理库存；每个实例一条占用，允许独立退款释放。 */
interface ProductOrderCapacityInterface
{
    /** @param array<string,mixed> $configuration 已封存的标准配置 @param list<int> $unitIds */
    public function occupy(string $type, array $configuration, array $unitIds): void;
    /** 仅在业务已确认资源终止且该实例全额退款后调用。 */
    public function release(string $type, int $unitId): void;
}
