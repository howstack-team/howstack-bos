<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\shared\infrastructure\TableQueryOrder;

use app\modules\member\infrastructure\model\MemberModel;
use app\modules\member\infrastructure\model\MemberGroupModel;
use app\modules\member\infrastructure\model\MemberLevelModel;
use support\Model;
use app\modules\member\contract\MemberCatalogRepositoryInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/** 使用数据库维护会员分组与等级。 */
final class DatabaseMemberCatalogRepository implements MemberCatalogRepositoryInterface
{
    /**
     * {@inheritDoc}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function groups(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        return $this->paginate('member_groups', $keyword, $status, $page, $pageSize, ['sort_order', 'id'], $filters);
    }

    /**
     * {@inheritDoc}
     */
    public function group(int $id): ?array
    {
        return $this->find('member_groups', $id);
    }

    /** {@inheritDoc} */
    public function groupCodeExists(string $code, ?int $exceptId): bool
    {
        return $this->codeExists('member_groups', $code, $exceptId);
    }

    /** {@inheritDoc} */
    public function saveGroup(?int $id, array $data): int
    {
        return $this->save('member_groups', $id, $data);
    }

    /** {@inheritDoc} */
    public function groupInUse(int $id): bool
    {
        return MemberModel::query()->where('group_id', $id)->exists();
    }

    /** {@inheritDoc} */
    public function deleteGroup(int $id): void
    {
        MemberGroupModel::query()->where('id', $id)->delete();
    }

    /**
     * {@inheritDoc}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function levels(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        return $this->paginate('member_levels', $keyword, $status, $page, $pageSize, ['level_order', 'id'], $filters);
    }

    /**
     * {@inheritDoc}
     */
    public function level(int $id): ?array
    {
        return $this->find('member_levels', $id);
    }

    /** {@inheritDoc} */
    public function levelCodeExists(string $code, ?int $exceptId): bool
    {
        return $this->codeExists('member_levels', $code, $exceptId);
    }

    /** {@inheritDoc} */
    public function saveLevel(?int $id, array $data): int
    {
        return $this->save('member_levels', $id, $data);
    }

    /** {@inheritDoc} */
    public function levelInUse(int $id): bool
    {
        return MemberModel::query()->where('level_id', $id)->exists();
    }

    /** {@inheritDoc} */
    public function deleteLevel(int $id): void
    {
        MemberLevelModel::query()->where('id', $id)->delete();
    }

    /** {@inheritDoc} */
    public function enabledOptions(): array
    {
        $map = static fn (Model $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name];

        return [
            'groups' => array_map($map, MemberGroupModel::query()->select(['id', 'name'])->where('status', 1)->orderBy('sort_order')->orderBy('id')->get()->all()),
            'levels' => array_map($map, MemberLevelModel::query()->select(['id', 'name'])->where('status', 1)->orderBy('level_order')->orderBy('id')->get()->all()),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    private function paginate(
        string $table,
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $orders,
        array $filters = [],
    ): array
    {
        $query = $this->modelQuery($table);
        if ($keyword !== '') {
            $query->where(static function (EloquentBuilder $nested) use ($keyword): void {
                $pattern = '%' . $keyword . '%';
                $nested->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern);
            });
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        foreach ([
            'name' => 'name',
            'code' => 'code',
        ] as $field => $column) {
            if (isset($filters[$field])) {
                $pattern = '%' . strtr($filters[$field], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $query->whereRaw($query->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        $total = (clone $query)->count();
        $sortColumns = ['created_at' => 'created_at'];
        foreach ($orders as $order) {
            $sortColumns[$order] = $order;
        }
        if ($table === 'member_levels') {
            $sortColumns['growth_threshold'] = 'growth_threshold';
        }
        TableQueryOrder::apply($query, $filters, $sortColumns, array_fill_keys($orders, 'asc'));

        $rows = $query->forPage($page, $pageSize)->get();

        return ['data' => array_map(self::normalize(...), $rows->all()), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    private function find(string $table, int $id): ?array
    {
        $row = $this->modelQuery($table)->where('id', $id)->first();

        return $row instanceof Model ? self::normalize($row) : null;
    }

    private function codeExists(string $table, string $code, ?int $exceptId): bool
    {
        $query = $this->modelQuery($table)->where('code', $code);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->exists();
    }

    /** @param array<string, mixed> $data */
    private function save(string $table, ?int $id, array $data): int
    {
        if ($id === null) {
            return (int) $this->modelQuery($table)->create($data)->getKey();
        }
        $this->modelQuery($table)->findOrFail($id)->fill($data)->save();

        return $id;
    }

    /** @return array<string, mixed> */
    private static function normalize(Model $row): array
    {
        $data = array_intersect_key($row->getAttributes(), array_flip([
            'id', 'name', 'code', 'sort_order', 'description', 'status', 'created_at', 'updated_at', 'level_order', 'growth_threshold',
        ]));
        foreach (['id', 'sort_order', 'level_order', 'growth_threshold', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (int) $data[$field];
            }
        }

        return $data;
    }

    /** 固定表名到模型的映射，拒绝动态表名。 */
    private function modelQuery(string $table): EloquentBuilder
    {
        return match ($table) {
            'member_groups' => MemberGroupModel::query(),
            'member_levels' => MemberLevelModel::query(),
            default => throw new \InvalidArgumentException('不支持的数据表'),
        };
    }
}
