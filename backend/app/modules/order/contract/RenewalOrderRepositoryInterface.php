<?php

declare(strict_types=1);

namespace app\modules\order\contract;

/** 服务域通过订单域端口创建独立续费交易，不跨模块写表。 */
interface RenewalOrderRepositoryInterface
{
    /**
     * @param string $key 有身份作用域的幂等标识
     * @param string $hash 请求摘要
     * @return int|null 既有订单
     */
    public function begin(string $key, string $hash): ?int;
    /**
     * @param string $key 幂等标识
     * @param int $id 新订单
     * @return void 绑定请求
     */
    public function finish(string $key, int $id): void;
    /**
     * @param int $memberId 原会员
     * @param int $serviceId 原实例
     * @param array<string,mixed> $quote 已核定报价
     * @return int 新订单
     */
    public function create(int $memberId, int $serviceId, array $quote): int;
    /**
     * @param int $id 订单
     * @return string 当前订单状态，只读且不反向锁订单
     */
    public function state(int $id): string;
}
