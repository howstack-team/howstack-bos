<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\exception\BusinessException;
use app\modules\compute\domain\ComputeTypeHandler;
use app\modules\product\contract\{ProductTypeRegistryInterface, ProductTypeHandlerInterface};
use app\shared\http\ApiErrorCode;

/** 首个内置类型注册；其他类型后续通过相同公开契约接入。 */
final readonly class ProductTypeRegistry implements ProductTypeRegistryInterface
{
    public function __construct(private ComputeTypeHandler $compute) {}

    public function get(string $type): ProductTypeHandlerInterface
    {
        return [$this->compute->type() => $this->compute][$type]
            ?? throw new BusinessException('产品类型尚未安装', ApiErrorCode::NOT_FOUND, 404);
    }
}
