<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 订单只消费已发布的产品契约，不读取产品类型内部配置表。 */
interface OrderSalesCatalogInterface
{
    /** @return list<array{id:int,name:string,product_type:string,version_id:int}> */
    public function choices(): array;
    /** @return array<string,mixed> 发布版本、产品身份及不可变销售快照。写流程必须锁定。 */
    public function published(int $offerId, int $versionId, bool $lock = false): array;
}
