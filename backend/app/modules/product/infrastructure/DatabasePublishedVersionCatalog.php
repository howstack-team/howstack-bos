<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\exception\BusinessException;
use app\modules\product\contract\PublishedVersionCatalogInterface;
use app\modules\product\infrastructure\model\OfferVersionModel;
use app\shared\http\ApiErrorCode;

/** 精确版本读取，不回查可变的资源配置。 */
final class DatabasePublishedVersionCatalog implements PublishedVersionCatalogInterface
{
    /** @inheritDoc */
    public function snapshot(int $offerId, int $versionId): array
    {
        $version = OfferVersionModel::query()->where('offer_id', $offerId)->find($versionId);
        if (!$version) {
            throw new BusinessException('原销售版本不可用，请联系管理员', ApiErrorCode::CONFLICT, 409);
        }
        return $version->snapshot;
    }
}
