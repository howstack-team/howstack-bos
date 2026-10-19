<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 存量服务续费可读取停售方案的最后发布版本，不等于允许新购。 */
interface RenewalSalesCatalogInterface
{
    /**
     * @param int $offerId 原购买方案
     * @return array<string,mixed> 当前发布快照
     */
    public function current(int $offerId): array;
}
