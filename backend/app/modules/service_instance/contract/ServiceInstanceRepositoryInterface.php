<?php

declare(strict_types=1);

namespace app\modules\service_instance\contract;

/** 后台已创建服务实例的只读查询契约。 */
interface ServiceInstanceRepositoryInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $productType,
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;
}
