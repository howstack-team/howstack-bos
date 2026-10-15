<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure;

use app\exception\BusinessException;
use app\modules\compute\contract\ComputeResourceRepositoryInterface;
use app\modules\compute\infrastructure\model\{RegionModel, ZoneModel, LineModel, FlavorGroupModel, FlavorModel, ImageTypeModel, ImageModel, DiskTypeModel, ProtectionModel, NetworkPolicyModel};
use app\shared\http\ApiErrorCode;
use Illuminate\Database\QueryException;
use support\Model;
use support\Db;

/** 资源 CRUD 只使用类型模型；模型字段由验证器收敛。 */
final class DatabaseComputeResourceRepository implements ComputeResourceRepositoryInterface
{
    private const array MODELS = ['regions' => RegionModel::class, 'zones' => ZoneModel::class, 'lines' => LineModel::class,
        'flavor_groups' => FlavorGroupModel::class, 'flavors' => FlavorModel::class, 'image_types' => ImageTypeModel::class,
        'images' => ImageModel::class, 'disk_types' => DiskTypeModel::class, 'protections' => ProtectionModel::class, 'network_policies' => NetworkPolicyModel::class];

    public function list(string $kind): array
    {
        $query = $this->model($kind)::query();
        if ($relation = $this->relation($kind)) $query->with($relation[0]);
        return $query->orderBy('sort')->orderBy('id')->get()->map(fn (Model $row): array => $this->map($row, $kind))->all();
    }

    public function find(string $kind, int $id): ?array
    {
        $query = $this->model($kind)::query();
        if ($relation = $this->relation($kind)) $query->with($relation[0]);
        $row = $query->find($id);
        return $row ? $this->map($row, $kind) : null;
    }

    public function save(string $kind, ?int $id, array $data): array
    {
        $class = $this->model($kind);
        try {
            return Db::transaction(function () use ($class, $kind, $id, $data): array {
                $row = $id === null ? new $class() : ($class::query()->lockForUpdate()->find($id) ?? throw new BusinessException('配置资源不存在', ApiErrorCode::NOT_FOUND, 404));
                if ($relation = $this->relation($kind)) if (isset($data[$relation[1]])) $data[$relation[2]] = $data[$relation[1]][0];
                $row->fill(array_intersect_key($data, array_flip($row->getFillable())))->save();
                if ($relation = $this->relation($kind)) {
                    [$method, $field, $scalar] = $relation;
                    if (isset($data[$field]) || isset($data[$scalar]) || $id === null) $row->{$method}()->sync($data[$field] ?? [$row->{$scalar}]);
                    $row->load($method);
                }
                return $this->map($row, $kind);
            });
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new BusinessException('资源编码重复或关联资源不存在', ApiErrorCode::CONFLICT, 409, previous: $error);
            }
            throw $error;
        }
    }

    /** @param string $kind 固定种类 @return class-string<Model> 模型类型 */
    private function model(string $kind): string
    {
        return self::MODELS[$kind] ?? throw new BusinessException('配置资源类型不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    /** @param Model $row 资源模型 @return array<string, mixed> 声明的可公开字段 */
    private function map(Model $row, string $kind): array
    {
        $data = ['id' => (int) $row->id];
        foreach ($row->getFillable() as $key) $data[$key] = str_ends_with($key, '_at') ? $row->{$key}?->format('Y-m-d H:i:s') : $row->{$key};
        if ($relation = $this->relation($kind)) $data[$relation[1]] = $row->getRelation($relation[0])->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        return $data;
    }

    /** @param string $kind 种类 @return array{string, string, string}|null ORM 关系、公开数组和旧主关联字段。 */
    private function relation(string $kind): ?array
    {
        return in_array($kind, ['lines', 'flavors', 'images', 'disk_types'], true) ? ['zones', 'zone_ids', 'zone_id'] : ($kind === 'protections' ? ['lines', 'line_ids', 'line_id'] : null);
    }
}
