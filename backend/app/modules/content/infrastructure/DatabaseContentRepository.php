<?php

declare(strict_types=1);

namespace app\modules\content\infrastructure;

use app\shared\infrastructure\TableQueryOrder;

use app\modules\content\infrastructure\model\ContentCategoryModel;
use app\modules\content\infrastructure\model\ContentEntryModel;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use support\Model;
use app\modules\content\contract\ContentRepositoryInterface;

/** 帮助与公告的分类、正文持久化。 */
final class DatabaseContentRepository implements ContentRepositoryInterface
{
    public function __construct(private \app\modules\file\contract\FileRegistryInterface $files) {}

    /** @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合 */
    public function paginate(
        string $kind,
        bool $categories,
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $keyword = $filters[$categories ? 'name' : 'title'] ?? $keyword;
        $table = $categories ? 'content_categories' : 'content_entries';
        $query = $this->modelQuery($table)->where('kind', $kind);
        if ($categories && $kind === 'help') {
            $all = array_map(self::normalize(...), TableQueryOrder::apply(
            $query, $filters, ['sort_order' => 'sort_order', 'created_at' => 'created_at'],
            ['sort_order' => 'asc', 'id' => 'desc'],
        )->get()->all());
            $children = [];
            foreach ($all as $category) {
                if ($category['parent_id'] !== null) $children[$category['parent_id']][] = $category;
            }
            $roots = [];
            foreach ($all as $category) {
                if ($category['parent_id'] !== null) continue;
                $matches = static fn (array $row): bool => ($keyword === '' || mb_stripos($row['name'], $keyword) !== false)
                    && ($status === null || (string) $row['status'] === $status);
                $rootMatches = $matches($category);
                $visibleChildren = array_values(array_filter($children[$category['id']] ?? [],
                    static fn (array $child): bool => ($status === null || (string) $child['status'] === $status)
                        && ($rootMatches || $keyword === '' || mb_stripos($child['name'], $keyword) !== false)));
                if (!$rootMatches && $visibleChildren === []) continue;
                if ($visibleChildren !== []) $category['children'] = $visibleChildren;
                $roots[] = $category;
            }
            return ['data' => $roots, 'total' => count($roots)];
        }
        if ($keyword !== '') {
            $column = $categories ? 'name' : 'title';
            $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->whereRaw($query->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
        }
        if ($status !== null) $query->where('status', $status);
        $total = (int) (clone $query)->count();
        $rows = TableQueryOrder::apply(
            $query, $filters, ['sort_order' => 'sort_order', 'created_at' => 'created_at'],
            ['sort_order' => 'asc', 'id' => 'desc'],
        )->forPage($page, $pageSize)->get();
        return ['data' => array_map(self::normalize(...), $rows->all()), 'total' => $total];
    }

    public function find(string $kind, bool $categories, int $id): ?array
    {
        $row = $this->modelQuery($categories ? 'content_categories' : 'content_entries')->where('kind', $kind)->where('id', $id)->first();
        return $row instanceof Model ? self::normalize($row) : null;
    }

    public function save(bool $categories, ?int $id, array $data): int
    {
        $table = $categories ? 'content_categories' : 'content_entries';
        return \support\Db::transaction(function () use ($categories, $table, $id, $data): int {
            $saved = $id ?? (int) $this->modelQuery($table)->create($data)->getKey();
            if ($id !== null) $this->modelQuery($table)->findOrFail($id)->fill($data)->save();
            if (!$categories) {
                $entry = $this->modelQuery($table)->findOrFail($saved);
                $this->files->syncReferences('content', (string) $saved, $entry->title, \app\modules\file\domain\FileReferences::collect($entry->body));
            }
            return $saved;
        });
    }

    public function delete(bool $categories, int $id): void
    {
        \support\Db::transaction(function () use ($categories, $id): void {
            $this->modelQuery($categories ? 'content_categories' : 'content_entries')->where('id', $id)->delete();
            if (!$categories) $this->files->syncReferences('content', (string)$id, '', []);
        });
    }

    public function categoryNameExists(string $kind, string $name, ?int $parentId, ?int $exceptId): bool
    {
        $query = ContentCategoryModel::query()->where('kind', $kind)->where('name', $name);
        $parentId === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);
        if ($exceptId !== null) $query->where('id', '<>', $exceptId);
        return $query->exists();
    }

    public function categoryInUse(int $id): bool
    {
        return ContentEntryModel::query()->where('category_id', $id)->exists() || $this->categoryHasChildren($id);
    }

    public function categoryHasChildren(int $id): bool
    {
        return ContentCategoryModel::query()->where('parent_id', $id)->exists();
    }

    /** @return array<string, mixed> */
    private static function normalize(Model $row): array
    {
        $data = array_intersect_key($row->getAttributes(), array_flip([
            'id', 'kind', 'name', 'sort_order', 'status', 'created_at', 'updated_at', 'parent_id', 'category_id', 'title', 'summary', 'body', 'body_format', 'published_at',
        ]));
        foreach (['id', 'category_id', 'parent_id', 'sort_order'] as $field) {
            if (isset($data[$field])) $data[$field] = (int) $data[$field];
        }
        if (isset($data['status']) && is_numeric($data['status'])) $data['status'] = (int) $data['status'];
        return $data;
    }

    /** 固定表名到模型的映射，拒绝动态表名。 */
    private function modelQuery(string $table): EloquentBuilder
    {
        return match ($table) {
            'content_categories' => ContentCategoryModel::query(),
            'content_entries' => ContentEntryModel::query(),
            default => throw new \InvalidArgumentException('不支持的数据表'),
        };
    }
}
