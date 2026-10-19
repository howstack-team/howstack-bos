<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\exception\BusinessException;
use app\modules\product\contract\RenewalSalesCatalogInterface;
use app\modules\product\infrastructure\model\{OfferModel, OfferVersionModel, ProductModel};
use app\shared\http\ApiErrorCode;

/** 已停售仍允许存量续费；没有发布快照时拒绝猜价，不回查可变资源表。 */
final class DatabaseRenewalSalesCatalog implements RenewalSalesCatalogInterface
{
    /** @inheritDoc */
    public function current(int $offerId): array
    {
        $offer = OfferModel::query()->find($offerId);
        $version = $offer ? OfferVersionModel::query()->where('offer_id', $offerId)->find($offer->published_version_id) : null;
        $product = $offer ? ProductModel::query()->find($offer->product_id) : null;
        if (!$version || !$product) {
            throw new BusinessException('原产品续费价格不可用，请联系管理员', ApiErrorCode::CONFLICT, 409);
        }
        return ['offer_id' => $offerId, 'version_id' => $version->id, 'product_type' => $product->definition_key,
            'type_version' => $version->type_version, 'product_name' => $version->snapshot['product_name'] ?? $product->name, 'snapshot' => $version->snapshot];
    }
}
