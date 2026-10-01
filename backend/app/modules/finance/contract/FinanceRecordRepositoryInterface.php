<?php

declare(strict_types=1);

namespace app\modules\finance\contract;

interface FinanceRecordRepositoryInterface
{
    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function paginate(string $resource, int $page, int $pageSize, ?string $status): array;

    /** @return array<string, mixed>|null */
    public function find(string $resource, int $id): ?array;

    /** 是否存在必须保留的会员资金记录。 */
    public function hasMemberRecords(int $memberId): bool;
}
