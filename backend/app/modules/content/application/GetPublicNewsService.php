<?php

declare(strict_types=1);

namespace app\modules\content\application;

use app\modules\content\contract\PublicNewsRepositoryInterface;

/** 匿名公告门面，不依赖后台内容编辑权限。 */
final readonly class GetPublicNewsService
{
    public function __construct(private PublicNewsRepositoryInterface $repository)
    {
    }

    /** @return list<array{id: int, title: string, summary: string, published_at: string}> 最多6条公开摘要 */
    public function latest(): array
    {
        return $this->repository->latest(6, date('Y-m-d H:i:s'));
    }
}
