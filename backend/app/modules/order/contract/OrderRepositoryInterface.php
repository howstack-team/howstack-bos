<?php

declare(strict_types=1);

namespace app\modules\order\contract;

/** 订单和订单项的后台只读查询契约。 */
interface OrderRepositoryInterface
{
    /**
     * 分页查询订单快照。
     * @param string      $keyword  订单号或会员关键词
     * @param string|null $status   订单状态
     * @param int         $page     页码
     * @param int         $pageSize 每页数量
     * @return array{data: list<array<string, mixed>>, total: int} 订单分页
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /**
     * 查询订单及其所有订单项。
     * @param int $id 订单标识
     * @return array<string, mixed>|null 订单不存在时返回 null
     */
    public function findWithItems(int $id): ?array;
}
