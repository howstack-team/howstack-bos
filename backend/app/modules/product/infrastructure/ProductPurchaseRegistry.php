<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\exception\BusinessException;
use app\modules\product\contract\{ProductPurchaseHandlerInterface, ProductPurchaseInputInterface, ProductPurchaseAvailabilityInterface, ProductOrderCapacityInterface, ProductPublicPurchaseInterface};
use app\shared\http\ApiErrorCode;
use InvalidArgumentException;

/** 单一、显式的购买接入注册；不依赖具体产品，未知类型不允许跳过库存及准入检查。 */
final readonly class ProductPurchaseRegistry implements ProductPurchaseInputInterface, ProductPurchaseAvailabilityInterface, ProductOrderCapacityInterface, ProductPublicPurchaseInterface
{
    /** @var array<string, ProductPurchaseHandlerInterface> */
    private array $handlers;

    /** @param iterable<ProductPurchaseHandlerInterface> $handlers 已安装的内置类型实现 */
    public function __construct(iterable $handlers)
    {
        $registered = [];
        foreach ($handlers as $handler) {
            $type = $handler->type();
            if (isset($registered[$type])) {
                throw new InvalidArgumentException('重复的产品购买处理器：' . $type);
            }
            $registered[$type] = $handler;
        }
        $this->handlers = $registered;
    }

    private function handler(string $type): ProductPurchaseHandlerInterface
    {
        return $this->handlers[$type]
            ?? throw new BusinessException('该产品尚未接入购买流程', ApiErrorCode::CONFLICT, 409);
    }

    public function clean(string $type, array $input): array
    {
        return $this->handler($type)->clean($input);
    }

    public function supportsPublic(string $type, array $snapshot): bool
    {
        return isset($this->handlers[$type]) && $this->handlers[$type]->supportsPublic($snapshot);
    }

    public function publicProduct(string $type, array $choice): array
    {
        return $this->handler($type)->publicProduct($choice);
    }

    public function publicOptions(string $type, array $resolved): array
    {
        return $this->handler($type)->publicOptions($resolved);
    }

    public function check(string $type, array $configuration): void
    {
        $this->handler($type)->check($configuration);
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
