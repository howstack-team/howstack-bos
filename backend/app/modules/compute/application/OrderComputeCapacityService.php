<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\modules\compute\contract\ComputeInventoryRepositoryInterface;

final readonly class OrderComputeCapacityService
{
    public function __construct(private ComputeInventoryRepositoryInterface $inventory) {}

    public function occupy(array $configuration, array $unitIds): void
    {
        foreach ($unitIds as $id) {
            $key = 'order-unit:' . $id;
            $this->inventory->reserve($configuration['zone_id'], $configuration['flavor_id'], $key, 1, new \DateTimeImmutable('+5 minutes'));
            $this->inventory->transition($key, 'occupied');
        }
    }

    public function release(int $unitId): void
    {
        $this->inventory->transition('order-unit:' . $unitId, 'released', true);
    }
}
