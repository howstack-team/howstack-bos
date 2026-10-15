<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\modules\product\contract\ProductOrderCapacityInterface;
use app\modules\compute\application\OrderComputeCapacityService;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 内置产品库存处理器注册点；订单和财务不按产品类型分支。 */
final readonly class ProductOrderCapacityRegistry implements ProductOrderCapacityInterface
{
    public function __construct(private OrderComputeCapacityService $compute) {}
    private function handler(string $type): object
    {
        return ['cloud_server' => $this->compute][$type]
            ?? throw new BusinessException('该产品尚未接入库存办理', ApiErrorCode::CONFLICT, 409);
    }
    public function occupy(string $type, array $configuration, array $unitIds): void
    {
        $this->handler($type)->occupy($configuration, $unitIds);
    }
    public function release(string $type, int $unitId): void
    {
        $this->handler($type)->release($unitId);
    }
}
