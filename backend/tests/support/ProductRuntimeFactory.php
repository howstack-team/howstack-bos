<?php

declare(strict_types=1);

namespace tests\support;

use app\modules\compute\application\{ComputePurchaseHandler, OrderComputeCapacityService};
use app\modules\compute\infrastructure\DatabaseComputeInventoryRepository;
use app\modules\product\infrastructure\ProductPurchaseRegistry;

/** 测试组合根，与生产一致注册真实云服务器实现；不替代数据库、计价或库存。 */
final class ProductRuntimeFactory
{
    public static function purchases(): ProductPurchaseRegistry
    {
        $inventory = new DatabaseComputeInventoryRepository();
        return new ProductPurchaseRegistry([new ComputePurchaseHandler($inventory, new OrderComputeCapacityService($inventory))]);
    }
}
