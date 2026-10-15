<?php

declare(strict_types=1);

namespace app\modules\content\infrastructure;

use app\modules\content\contract\PublicNewsRepositoryInterface;
use app\modules\content\infrastructure\model\ContentEntryModel;
use app\modules\content\infrastructure\model\ContentCategoryModel;

/** 公开查询强制状态、发布时间、分类状态；不返回正文、草稿或未来公告。 */
final class DatabasePublicNewsRepository implements PublicNewsRepositoryInterface
{
    /** @inheritDoc */
    public function latest(int $limit, string $now): array
    {
        $rows = ContentEntryModel::query()->where('kind', 'news')->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', $now)
            ->whereIn('category_id', ContentCategoryModel::query()->select('id')->where('kind', 'news')->where('status', 1))
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get(['id', 'title', 'summary', 'published_at']);
        return $rows->map(static fn (ContentEntryModel $entry): array => ['id' => (int) $entry->id, 'title' => $entry->title,
            'summary' => mb_substr(strip_tags($entry->summary ?? ''), 0, 180), 'published_at' => $entry->published_at->format('Y-m-d H:i:s')])->all();
    }
}
