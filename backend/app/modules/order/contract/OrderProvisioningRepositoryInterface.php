<?php

declare(strict_types=1);

namespace app\modules\order\contract;

/** 订单模块拥有实例分摊与交易状态；履约通过本契约关联服务，不跨模块写表。 */
interface OrderProvisioningRepositoryInterface
{
    /** @param int $orderId 订单 @return array<string,mixed> 事务内锁定订单、订单项和单位 */
    public function lock(int $orderId): array;
    /** @param int $unitId 订单实例单元 @param int $serviceId 服务 @return void 首次关联，禁止替换 */
    public function link(int $unitId, int $serviceId): void;
    /** @param int $orderId 订单 @param string $status fulfilling/completed/failed @return void 更新业务履约状态，退款状态不覆盖 */
    public function status(int $orderId, string $status): void;
}
