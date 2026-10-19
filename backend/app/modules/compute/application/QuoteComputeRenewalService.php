<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\exception\BusinessException;
use app\modules\compute\domain\ComputeTypeHandler;
use app\modules\product\contract\{ProductRenewalPricingInterface, RenewalSalesCatalogInterface};
use app\shared\http\ApiErrorCode;

/** 云服务器按原配置、当前销售版本的可用区价格续费；不查询或占用库存。 */
final readonly class QuoteComputeRenewalService implements ProductRenewalPricingInterface
{
    /** @param RenewalSalesCatalogInterface $catalog 当前不可变销售快照 */
    public function __construct(private RenewalSalesCatalogInterface $catalog)
    {
    }

    /** @inheritDoc */
    public function quote(string $type, array $origin, int $months): array
    {
        if ($type !== 'cloud_server') {
            throw new BusinessException('该产品尚未开放续费', ApiErrorCode::CONFLICT, 409);
        }
        $sale = $this->catalog->current((int) $origin['offer_id']);
        if ($sale['product_type'] !== $type || ($sale['snapshot']['schema_version'] ?? '') !== '2.0.0') {
            throw new BusinessException('当前销售配置不支持续费，请联系管理员', ApiErrorCode::CONFLICT, 409);
        }
        $selection = $origin['configuration'];
        $selection['quantity'] = 1;
        unset($selection['duration_months']);
        $handler = new ComputeTypeHandler();
        $resolved = $handler->resolve($sale['snapshot'], $selection);
        $durations = $resolved['durations'];
        if ($months === 0) {
            return ['durations' => $durations];
        }
        $selection['duration_months'] = $months;
        $price = $handler->price($sale['snapshot'], $selection);
        $parts = array_values(array_filter($price['unit_breakdown'], static fn (array $part): bool => ($part['recurring'] ?? false) === true));
        $price['unit_breakdown'] = $parts;
        $price['subtotal_minor'] = array_sum(array_column($parts, 'subtotal_minor'));
        $price['amount_minor'] = $price['unit_amount_minor'] = array_sum(array_column($parts, 'amount_minor'));
        $price['discount_minor'] = $price['subtotal_minor'] - $price['amount_minor'];
        if ($price['currency'] !== 'CNY' || $price['amount_minor'] <= 0 || $price['amount_minor'] > 100000000) {
            throw new BusinessException('续费金额无效，单笔上限为100万元', ApiErrorCode::CONFLICT, 409);
        }
        return ['durations' => $durations, 'sale' => $sale, 'price' => $price];
    }
}
