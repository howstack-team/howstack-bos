<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 经代码发布或插件审核注册的类型目录。 */
interface ProductTypeRegistryInterface
{
    /** @param string $type 产品类型 @return ProductTypeHandlerInterface 类型处理器 */
    public function get(string $type): ProductTypeHandlerInterface;
}
