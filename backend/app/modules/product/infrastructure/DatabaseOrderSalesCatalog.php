<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\modules\product\contract\OrderSalesCatalogInterface;
use app\modules\product\infrastructure\model\{OfferModel, OfferVersionModel, ProductModel};
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

final class DatabaseOrderSalesCatalog implements OrderSalesCatalogInterface
{
    public function choices(): array
    {
        $rows = OfferModel::query()->where('status', 1)->whereNotNull('published_version_id')->orderBy('id')->get(['id','name','product_id','published_version_id']);
        $products = ProductModel::query()->whereIn('id', $rows->pluck('product_id'))->get(['id','name','definition_key'])->keyBy('id');
        return $rows->map(static fn (OfferModel $row): array => ['id' => $row->id, 'name' => $products[$row->product_id]->name . ' · ' . $row->name,
            'product_type' => $products[$row->product_id]->definition_key, 'version_id' => $row->published_version_id])->all();
    }

    public function published(int $offerId, int $versionId, bool $lock = false): array
    {
        $query = OfferModel::query()->whereKey($offerId);
        if ($lock) $query->lockForUpdate();
        $offer = $query->first();
        if (!$offer || $offer->status !== 1 || $offer->published_version_id !== $versionId) {
            throw new BusinessException('产品已停售或发布版本已变化，请重新选择并计算费用', ApiErrorCode::CONFLICT, 409);
        }
        $version = OfferVersionModel::query()->where('offer_id', $offerId)->find($versionId);
        $product = ProductModel::query()->find($offer->product_id);
        if (!$version || !$product) throw new BusinessException('发布版本不存在', ApiErrorCode::CONFLICT, 409);
        return ['offer_id' => $offerId, 'version_id' => $versionId, 'product_id' => $product->id, 'product_type' => $product->definition_key,
            'product_name' => $version->snapshot['product_name'] ?? $product->name, 'type_version' => $version->type_version, 'snapshot' => $version->snapshot];
    }
}
