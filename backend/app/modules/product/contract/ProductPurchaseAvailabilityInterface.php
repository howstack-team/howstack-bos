<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 购买阶段只检查可售余量；正式库存占用仍在付款事务内进行。 */
interface ProductPurchaseAvailabilityInterface
{
    /** @param string $type 产品类型 @param array<string, mixed> $configuration 标准购买参数 @return void */
    public function check(string $type, array $configuration): void;
}
