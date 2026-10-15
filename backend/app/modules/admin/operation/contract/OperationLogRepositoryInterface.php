<?php

declare(strict_types=1);

namespace app\modules\admin\operation\contract;

/** 管理员操作日志的写入、查询和保留期清理边界。 */
interface OperationLogRepositoryInterface
{
    /**
     * @param array<string, int|string|null> $record 已脱敏的操作快照
     */
    public function append(array $record): void;

    /**
     * @return array{data: list<array<string, int|string>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /** @return array<string, int|string|null>|null */
    public function find(int $id): ?array;

    public function countBefore(string $cutoff): int;

    public function deleteBatchBefore(string $cutoff, int $limit): int;
}
