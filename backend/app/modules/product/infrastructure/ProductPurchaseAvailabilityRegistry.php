<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\modules\compute\contract\ComputeInventoryRepositoryInterface;
use app\modules\product\contract\ProductPurchaseAvailabilityInterface;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 类型库存端口的购买适配；不在匿名选配或未付款订单中预占库存。 */
final readonly class ProductPurchaseAvailabilityRegistry implements ProductPurchaseAvailabilityInterface
{
    public function __construct(private ComputeInventoryRepositoryInterface $inventory) {}

    /** @inheritDoc */
    public function check(string $type, array $configuration): void
    {
        if ($type !== 'cloud_server') return;
        foreach ($this->inventory->all() as $pool) {
            if ($pool['zone_id'] === $configuration['zone_id'] && $pool['flavor_id'] === $configuration['flavor_id']
                && $pool['available'] !== null && $pool['available'] < ($configuration['quantity'] ?? 1)) {
                throw new BusinessException('所选规格库存不足，请调整配置或购买数量', ApiErrorCode::CONFLICT, 409);
            }
        }
    }
}
