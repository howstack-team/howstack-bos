<?php

declare(strict_types=1);

namespace app\modules\product\application;

use app\modules\product\contract\OrderSalesCatalogInterface;
use app\modules\product\contract\ProductTypeRegistryInterface;
use app\modules\product\contract\ProductPurchaseInputInterface;
use app\modules\product\contract\ProductPurchaseAvailabilityInterface;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 匿名产品目录、选配和报价；绝不公开销售快照、提供者及交付映射。 */
final readonly class PublicProductPurchaseService
{
    public function __construct(private OrderSalesCatalogInterface $catalog, private ProductTypeRegistryInterface $types,
        private ProductPurchaseInputInterface $inputs, private ProductPurchaseAvailabilityInterface $availability,
        private \app\modules\product\contract\ProductPublicPurchaseInterface $publicPurchases) {}

    /** @return list<array<string, mixed>> 当前支持公开购买的已启用产品 */
    public function products(): array
    {
        $result = [];
        foreach ($this->catalog->choices() as $choice) {
            try { $sale = $this->catalog->published($choice['id'], $choice['version_id']); }
            catch (BusinessException) { continue; }
            if (!$this->publicPurchases->supportsPublic($sale['product_type'], $sale['snapshot'])) {
                continue;
            }
            $result[] = $this->publicPurchases->publicProduct($sale['product_type'], $choice);
        }
        return $result;
    }

    /** @param int $offerId 产品配置 @param int $versionId 当前销售版本 @param array<string, mixed> $selection 选配 @return array<string, mixed> 公开联动选项 */
    public function options(int $offerId, int $versionId, array $selection): array
    {
        $sale = $this->sale($offerId, $versionId);
        $result = $this->types->get($sale['product_type'])->resolve($sale['snapshot'], $this->inputs->clean($sale['product_type'], $selection));
        return $this->publicPurchases->publicOptions($sale['product_type'], $result);
    }

    /** @param int $offerId 产品配置 @param int $versionId 当前版本 @param array<string, mixed> $selection 完整选择 @return array<string, mixed> 服务端计算的整数分报价 */
    public function quote(int $offerId, int $versionId, array $selection): array
    {
        $sale = $this->sale($offerId, $versionId);
        $price = $this->types->get($sale['product_type'])->price($sale['snapshot'], $this->inputs->clean($sale['product_type'], $selection));
        $this->availability->check($sale['product_type'], $price['configuration']);
        $price['unit_breakdown'] = array_map(static fn (array $part): array => array_intersect_key($part, array_flip([
            'key', 'label', 'quantity', 'unit_price_minor', 'amount_minor', 'pricing_mode', 'recurring', 'subtotal_minor', 'discount_minor',
        ])), $price['unit_breakdown']);
        return array_intersect_key($price, array_flip(['configuration', 'currency', 'quantity', 'unit_amount_minor', 'amount_minor', 'subtotal_minor', 'discount_minor', 'unit_breakdown']));
    }

    /** @param int $offerId 产品配置 @param int $versionId 销售版本 @return array<string, mixed> 内部销售资料 */
    private function sale(int $offerId, int $versionId): array
    {
        $sale = $this->catalog->published($offerId, $versionId);
        if (!$this->publicPurchases->supportsPublic($sale['product_type'], $sale['snapshot'])) {
            throw new BusinessException('该产品尚未开放会员购买', ApiErrorCode::NOT_FOUND, 404);
        }
        return $sale;
    }
}
