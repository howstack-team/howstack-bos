<?php

declare(strict_types=1);

namespace app\modules\member\contract;

/** 会员分组与等级目录持久化契约。 */
interface MemberCatalogRepositoryInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function groups(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function group(int $id): ?array;

    public function groupCodeExists(string $code, ?int $exceptId): bool;

    /** @param array<string, mixed> $data */
    public function saveGroup(?int $id, array $data): int;

    public function groupInUse(int $id): bool;

    public function deleteGroup(int $id): void;

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function levels(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /** @return array<string, mixed>|null */
    public function level(int $id): ?array;

    public function levelCodeExists(string $code, ?int $exceptId): bool;

    /** @param array<string, mixed> $data */
    public function saveLevel(?int $id, array $data): int;

    public function levelInUse(int $id): bool;

    public function deleteLevel(int $id): void;

    /** @return array{groups: list<array{id: int, name: string}>, levels: list<array{id: int, name: string}>} */
    public function enabledOptions(): array;
}
