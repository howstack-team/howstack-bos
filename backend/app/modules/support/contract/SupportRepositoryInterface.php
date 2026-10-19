<?php

declare(strict_types=1);

namespace app\modules\support\contract;

interface SupportRepositoryInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function catalog(
        string $table,
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;
    /**
     * @return array<string, mixed>|null
     */
    public function catalogRow(string $table, int $id): ?array;
    public function typeCodeExists(string $code, ?int $exceptId): bool;
    /** @param array<string, mixed> $data */
    public function saveCatalog(string $table, ?int $id, array $data): int;
    public function deleteCatalog(string $table, int $id): void;
    public function typeInUse(int $id): bool;
    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function tickets(
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;
    /** @return array<string, mixed>|null */
    public function ticket(int $id): ?array;
}
