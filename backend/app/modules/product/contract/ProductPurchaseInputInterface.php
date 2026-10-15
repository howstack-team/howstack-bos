<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 各类型负责购买输入白名单；公共订单不解释类型字段。 */
interface ProductPurchaseInputInterface
{
    public function clean(string $type, array $input): array;
}
