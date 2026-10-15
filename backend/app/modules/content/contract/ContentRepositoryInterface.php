<?php

declare(strict_types=1);

namespace app\modules\content\contract;

interface ContentRepositoryInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $kind,
        bool $categories,
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;
    /** @return array<string, mixed>|null */
    public function find(string $kind, bool $categories, int $id): ?array;
    /** @param array<string, mixed> $data */
    public function save(bool $categories, ?int $id, array $data): int;
    public function delete(bool $categories, int $id): void;
    public function categoryNameExists(string $kind, string $name, ?int $parentId, ?int $exceptId): bool;
    public function categoryInUse(int $id): bool;
    public function categoryHasChildren(int $id): bool;
}
