<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

use InvalidArgumentException;

/** 只读目录查询，目录种类须同时位于插件清单中。 */
final readonly class CatalogQuery
{
    /**
     * 限定分页范围。
     * @param string $kind    已声明的目录名
     * @param int    $page    页码，从 1 开始
     * @param int    $perPage 每页数量，最多 100
     * @throws InvalidArgumentException 分页不合法
     */
    public function __construct(public string $kind, public int $page = 1, public int $perPage = 50)
    {
        if ($page < 1 || $page > 10000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('目录分页参数超出范围');
        }
    }
}
