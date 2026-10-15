<?php

declare(strict_types=1);

namespace app\modules\order\contract;

interface OrderManagementRepositoryInterface
{
    /** @return list<array{id:int,label:string}> 必要会员选择信息 */
    public function members(string $search): array;
    /** @return array{id:int,mobile:string,nickname:string,status:int}|null */
    public function member(int $id): ?array;
    /** 同键内容必须一致；必须在事务中调用。 */
    public function begin(string $key, string $hash): ?int;
    public function finish(string $key, int $orderId): void;
    /** 创建主单、订单项、实例分摊，保存报价和标准配置。 */
    public function create(array $member, array $catalog, array $price, array $provisioning, string $remark): int;
    /** @return array<string,mixed> 当前默认事务内锁定 */
    public function lock(int $id): array;
    public function cancel(int $id): void;
    public function remark(int $id, string $remark, string $expected): void;
}
