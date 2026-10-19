<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\exception\BusinessException;
use app\modules\product\contract\{ProductTypeRegistryInterface, ProductTypeHandlerInterface};
use app\shared\http\ApiErrorCode;
use InvalidArgumentException;

/** 类型只在组合根注册，公共产品层不依赖云服务器或其他具体类型。 */
final readonly class ProductTypeRegistry implements ProductTypeRegistryInterface
{
    /** @var array<string, ProductTypeHandlerInterface> */
    private array $handlers;

    /** @param iterable<ProductTypeHandlerInterface> $handlers 已安装类型 */
    public function __construct(iterable $handlers)
    {
        $registered = [];
        foreach ($handlers as $handler) {
            if (isset($registered[$handler->type()])) {
                throw new InvalidArgumentException('重复的产品类型：' . $handler->type());
            }
            $registered[$handler->type()] = $handler;
        }
        $this->handlers = $registered;
    }

    public function get(string $type): ProductTypeHandlerInterface
    {
        return $this->handlers[$type]
            ?? throw new BusinessException('产品类型尚未安装', ApiErrorCode::NOT_FOUND, 404);
    }
}
