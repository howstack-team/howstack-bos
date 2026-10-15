<?php

declare(strict_types=1);

namespace app\modules\member\contract;

/** 实名认证申请与审核记录持久化契约。 */
interface RealNameAuthenticationRepositoryInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $keyword,
        ?string $type,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    public function hasPending(int $memberId): bool;

    /** @return array<string, mixed>|null */
    public function latestApproved(int $memberId, string $type): ?array;

    /** @param array<string, mixed> $data */
    public function create(array $data): int;

    public function reviewPending(int $id, string $status, int $reviewerId, string $reason): bool;
}
