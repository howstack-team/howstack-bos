<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

/** 供应目录的受控分页结果；只保留稳定标识和安全元数据。 */
final readonly class CatalogPage
{
    /**
     * 返回标准目录。
     * @param list<array{external_id: string, name: string, metadata: array<string, string|int|bool>}> $entries 目录项
     * @param int $total 总数
     * @param int $page  当前页
     */
    public function __construct(public array $entries, public int $total, public int $page) {}
}
