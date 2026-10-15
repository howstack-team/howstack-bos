<?php

declare(strict_types=1);

namespace app\shared\infrastructure;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/** 对完整查询应用固定列映射的排序，并追加唯一键保证分页稳定。 */
final class TableQueryOrder
{
    /**
     * @param array<string, string> $filters 已验证的列表查询参数
     * @param array<string, string> $columns 业务字段到固定 SQL 列名的映射
     * @param array<string, string> $defaults 未指定排序时的原有顺序
     * @return EloquentBuilder|Builder 原查询对象
     */
    public static function apply(
        EloquentBuilder|Builder $query,
        array $filters,
        array $columns,
        array $defaults = ['id' => 'desc'],
        string $uniqueKey = 'id',
    ): EloquentBuilder|Builder {
        $field = $filters['sort_by'] ?? '';
        if (isset($columns[$field])) {
            $column = $columns[$field];
            $direction = ($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $wrapped = $query->getGrammar()->wrap($column);
            $query->orderByRaw('CASE WHEN ' . $wrapped . ' IS NULL THEN 1 ELSE 0 END ASC');
            $query->orderBy($column, $direction);
        } else {
            foreach ($defaults as $column => $direction) {
                $query->orderBy($column, $direction);
            }
        }
        if (!array_key_exists($uniqueKey, $defaults) || isset($columns[$field])) {
            $query->orderBy($uniqueKey, 'desc');
        }

        return $query;
    }
}
