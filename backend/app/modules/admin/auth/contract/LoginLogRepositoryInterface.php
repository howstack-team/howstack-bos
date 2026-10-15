<?php

declare(strict_types=1);

namespace app\modules\admin\auth\contract;

/** 管理员登录记录的写入、查询和过期清理。 */
interface LoginLogRepositoryInterface
{
    /**
     * 保存登录事件快照。
     * @param array<string, int|string|null> $record 登录事件快照
     * @return void
     */
    public function append(array $record): void;

    /**
     * 分页查询登录记录。
     * @param string   $keyword  搜索词
     * @param int|null $status   登录状态；null 表示全部
     * @param int      $page     页码
     * @param int      $pageSize 每页条数
     * @return array{data: list<array<string, mixed>>, total: int} 分页记录及总数
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /**
     * 统计指定时间之前的记录。
     * @param string $cutoff 截止时间
     * @return int 过期记录数
     */
    public function countBefore(string $cutoff): int;

    /**
     * 小批量删除指定时间之前的记录。
     * @param string $cutoff 截止时间
     * @param int    $limit  单批最多删除条数
     * @return int 实际删除条数
     */
    public function deleteBatchBefore(string $cutoff, int $limit): int;
}
