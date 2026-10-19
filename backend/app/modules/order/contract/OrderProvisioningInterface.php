<?php

declare(strict_types=1);

namespace app\modules\order\contract;

/** 与创建、付款共用本地事务；只写持久任务，不在交易事务内请求供应商。 */
interface OrderProvisioningInterface
{
    /** @param int $orderId 新订单 @param string|null $password 用户设置的初始密码，不传则由宿主生成 @return void */
    public function prepare(int $orderId, #[\SensitiveParameter] ?string $password): void;
    /** @param int $orderId 已锁定且已付款订单 @return void 创建每台实例的交付任务 */
    public function enqueue(int $orderId): void;
}
