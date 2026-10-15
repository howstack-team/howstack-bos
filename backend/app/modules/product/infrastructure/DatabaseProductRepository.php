<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\shared\infrastructure\TableQueryOrder;

use app\exception\BusinessException;
use app\modules\product\contract\ProductRepositoryInterface;
use app\modules\product\infrastructure\model\{ProductModel, OfferModel, OfferVersionModel};
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use Illuminate\Database\QueryException;

/** 单模块写仓储；供应绑定写入通过独立 Contract 完成。 */
final readonly class DatabaseProductRepository implements ProductRepositoryInterface
{
    public function __construct(private TransactionManagerInterface $transactions, private \app\modules\provider\contract\ProviderBindingWriterInterface $bindings) {}

    /** @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合 */
    public function paginate(
        string $keyword,
        int $page,
        int $size,
        array $filters = [],
    ): array
    {
        $query = OfferModel::query()->where('product_id', $this->fixedProduct()['id']);
        if ($keyword !== '') $query->where(static fn ($q) => $q->where('name', 'like', '%' . $keyword . '%')->orWhere('code', 'like', '%' . $keyword . '%'));
        foreach ([
            'name' => 'name',
            'code' => 'code',
        ] as $field => $column) {
            if (isset($filters[$field])) {
                $pattern = '%' . strtr($filters[$field], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $query->whereRaw($query->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['publication'])) {
            if ($filters['publication'] === 'published') {
                $query->whereNotNull('published_version_id');
            } else {
                $query->whereNull('published_version_id');
            }
        }
        $total = $query->count();
        return ['data' => TableQueryOrder::apply($query, $filters, [
                'created_at' => 'created_at',
            ], ['id' => 'desc'], 'id')->forPage($page, $size)->get()->map(fn (OfferModel $row): array => $this->map($row, false))->all(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $row = OfferModel::query()->where('product_id', $this->fixedProduct()['id'])->find($id);
        return $row ? $this->map($row, true) : null;
    }

    public function workspaceConfiguration(): array
    {
        $product = ProductModel::query()->where('definition_key', 'cloud_server')->firstOrFail();
        $rows = OfferModel::query()->where('product_id', $product->id)->orderBy('id')->get();
        $id = $product->workspace_configuration_id;
        if ($id === null && $rows->count() === 1) {
            $id = (int) $rows->first()->id;
        }
        return [
            'configuration' => $id === null ? null : $this->find($id),
            'choices' => $rows->map(fn (OfferModel $row): array => $this->map($row, false))->all(),
            'default_configuration_id' => $product->workspace_configuration_id,
        ];
    }

    public function saveWorkspaceConfiguration(?int $id, array $data, ?int $expectedId): array
    {
        return $this->transactions->run(function () use ($id, $data, $expectedId): array {
            $product = ProductModel::query()->where('definition_key', 'cloud_server')->lockForUpdate()->firstOrFail();
            if ($product->workspace_configuration_id !== $expectedId) {
                throw new BusinessException('当前配置已被切换，请刷新后重试', ApiErrorCode::CONFLICT, 409);
            }
            if ($id === null && OfferModel::query()->where('product_id', $product->id)->exists()) {
                throw new BusinessException('已有配置，请先明确选择需要使用的配置', ApiErrorCode::CONFLICT, 409);
            }
            if ($id !== null && $this->find($id) === null) {
                throw new BusinessException('配置不存在或不属于云服务器', ApiErrorCode::NOT_FOUND, 404);
            }
            $saved = $this->save($id, $data);
            $product->workspace_configuration_id = $saved['id'];
            $product->save();
            return $saved;
        });
    }

    public function save(?int $id, array $data): array
    {
        return $this->transactions->run(function () use ($id, $data): array {
            $row = $id === null ? new OfferModel() : $this->lock($id, $data['revision']);
            $row->fill(['product_id' => $this->fixedProduct()['id'], 'name' => $data['name'], 'code' => $data['code'], 'description' => $data['description'], 'draft' => $data['draft'], 'revision' => ($row->revision ?? 0) + 1]);
            if ($id === null) $row->status = 0;
            try { $row->save(); }
            catch (QueryException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23505'], true)) throw new BusinessException('销售方案标识已存在', ApiErrorCode::CONFLICT, 409, previous: $error);
                throw $error;
            }
            return $this->find((int) $row->id);
        });
    }

    public function publish(int $id, int $revision, string $typeVersion, array $snapshot, array $binding, int $actorId): array
    {
        return $this->transactions->run(function () use ($id, $revision, $typeVersion, $snapshot, $binding, $actorId): array {
            $row = $this->lock($id, $revision);
            $hash = hash('sha256', json_encode([$snapshot, $binding, $typeVersion], JSON_THROW_ON_ERROR));
            $latest = OfferVersionModel::query()->where('offer_id', $id)->orderByDesc('version')->first();
            if ($latest?->snapshot_hash === $hash) return $this->find($id);
            $version = OfferVersionModel::query()->create(['offer_id' => $id, 'version' => ($latest?->version ?? 0) + 1,
                'type_version' => $typeVersion, 'snapshot' => $snapshot, 'snapshot_hash' => $hash, 'published_by' => $actorId]);
            $this->bindings->write((int) $version->id, $binding);
            $row->published_version_id = $version->id;
            $row->revision++;
            // 发布版本不自动启用销售，启停是独立的受权操作。
            $row->save();
            return $this->find($id);
        });
    }

    public function setStatus(int $id, int $revision, int $status): array
    {
        return $this->transactions->run(function () use ($id, $revision, $status): array {
            $row = $this->lock($id, $revision);
            if ($status === 1 && $row->published_version_id === null) throw new BusinessException('请先完成销售配置并发布版本');
            $row->status = $status;
            $row->revision++;
            $row->save();
            return $this->find($id);
        });
    }

    public function versions(int $id): array
    {
        return OfferVersionModel::query()->where('offer_id', $id)->orderByDesc('version')->get()
            ->map(static fn (OfferVersionModel $r): array => ['id' => $r->id, 'version' => $r->version, 'type_version' => $r->type_version,
                'snapshot' => $r->snapshot, 'snapshot_hash' => $r->snapshot_hash, 'published_by' => $r->published_by,
                'created_at' => $r->created_at->format('Y-m-d H:i:s')])->all();
    }

    /** @param int $id 方案 @param int $revision 客户端版本 @return OfferModel 已锁定方案 */
    private function lock(int $id, int $revision): OfferModel
    {
        $row = OfferModel::query()->where('product_id', $this->fixedProduct()['id'])->lockForUpdate()->find($id) ?? throw new BusinessException('销售方案不存在', ApiErrorCode::NOT_FOUND, 404);
        if ($row->revision !== $revision) throw new BusinessException('配置已被修改，请刷新后重试', ApiErrorCode::CONFLICT, 409);
        return $row;
    }

    /** @param OfferModel $row 方案 @param bool $detail 是否详情 @return array<string, mixed> 安全字段 */
    private function map(OfferModel $row, bool $detail): array
    {
        $pending = false;
        if ($detail && $row->published_version_id !== null) {
            $snapshot = OfferVersionModel::query()->where('offer_id', $row->id)->find($row->published_version_id)?->snapshot;
            $pending = isset($snapshot['source_draft_hash'])
                && $snapshot['source_draft_hash'] !== hash('sha256', json_encode($row->draft, JSON_THROW_ON_ERROR));
        }
        return ['id' => $row->id, 'product_id' => $row->product_id, 'product_type' => 'cloud_server',
            'name' => $row->name, 'code' => $row->code, 'description' => $row->description, 'status' => $row->status,
            'revision' => $row->revision, 'published_version_id' => $row->published_version_id,
            'created_at' => $row->created_at->format('Y-m-d H:i:s'), 'updated_at' => $row->updated_at->format('Y-m-d H:i:s')]
            + ($detail ? ['draft' => $row->draft, 'has_unpublished_changes' => $pending] : []);
    }

    public function fixedProduct(): array
    {
        $row = ProductModel::query()->where('definition_key', 'cloud_server')->first()
            ?? throw new BusinessException('云服务器配置尚未完成数据库升级', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        return ['id' => $row->id, 'name' => $row->name];
    }

    public function overview(): array
    {
        $query = OfferModel::query()->where('product_id', $this->fixedProduct()['id']);
        return ['total' => (clone $query)->count(), 'published' => (clone $query)->whereNotNull('published_version_id')->count(),
            'enabled' => (clone $query)->where('status', 1)->count()];
    }
}
