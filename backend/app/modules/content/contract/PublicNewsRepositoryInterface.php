<?php

declare(strict_types=1);

namespace app\modules\content\contract;

/** 内容模块对匿名官网提供已发布摘要，不允许读取完整私有模型。 */
interface PublicNewsRepositoryInterface
{
    /** @return list<array{id: int, title: string, summary: string, published_at: string}> 公开摘要 */
    public function latest(int $limit, string $now): array;
}
