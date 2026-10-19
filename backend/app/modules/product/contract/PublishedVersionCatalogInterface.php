<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 存量资源读取原不可变销售版本，不随当前发布或停售变化。 */
interface PublishedVersionCatalogInterface
{
    /**
     * @param int $offerId 原方案
     * @param int $versionId 原版本
     * @return array<string,mixed> 原封存快照
     */
    public function snapshot(int $offerId, int $versionId): array;
}
