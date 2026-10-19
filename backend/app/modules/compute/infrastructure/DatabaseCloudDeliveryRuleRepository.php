<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure;

use app\exception\BusinessException;
use app\modules\compute\contract\CloudDeliveryRuleRepositoryInterface;
use app\modules\product\contract\ProductRepositoryInterface;
use app\modules\compute\infrastructure\model\CloudDeliveryRuleModel;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

final readonly class DatabaseCloudDeliveryRuleRepository implements CloudDeliveryRuleRepositoryInterface
{
    public function __construct(private ProductRepositoryInterface $products, private TransactionManagerInterface $transactions) {}

    public function all(): array
    {
        return CloudDeliveryRuleModel::query()->where('product_id', $this->products->fixedProduct()['id'])->orderBy('priority')->orderBy('id')->get()
            ->map(fn (CloudDeliveryRuleModel $row): array => $this->map($row))->all();
    }

    public function find(int $id): ?array
    {
        $row = CloudDeliveryRuleModel::query()->where('product_id', $this->products->fixedProduct()['id'])->find($id);
        return $row ? $this->map($row) : null;
    }

    public function lock(int $id): ?array
    {
        $row = CloudDeliveryRuleModel::query()->where('product_id', $this->products->fixedProduct()['id'])->lockForUpdate()->find($id);
        return $row ? $this->map($row) : null;
    }

    public function save(?int $id, array $data): array
    {
        return $this->transactions->run(function () use ($id, $data): array {
            $productId = $this->products->fixedProduct()['id'];
            $row = $id === null ? new CloudDeliveryRuleModel() : CloudDeliveryRuleModel::query()->where('product_id', $productId)->lockForUpdate()->find($id);
            if (!$row) throw new BusinessException('交付规则不存在', ApiErrorCode::NOT_FOUND, 404);
            if ($id !== null && $row->revision !== $data['revision']) throw new BusinessException('交付规则已被修改，请刷新后重试', ApiErrorCode::CONFLICT, 409);
            $row->fill(array_replace($data, ['product_id' => $productId]));
            $row->revision = ($id === null ? 0 : $data['revision']) + 1;
            $row->save();
            return $this->map($row);
        });
    }

    /** @param CloudDeliveryRuleModel $row 模型 @return array<string, mixed> 安全输出。 */
    private function map(CloudDeliveryRuleModel $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'description' => $row->description, 'connection_id' => $row->connection_id,
            'parameter_mapping' => $row->parameter_mapping ?? [], 'line_ids' => $row->line_ids, 'resource_ids' => $row->resource_ids ?? [], 'priority' => $row->priority, 'status' => $row->status, 'revision' => $row->revision,
            'created_at' => $row->created_at->format('Y-m-d H:i:s'), 'updated_at' => $row->updated_at->format('Y-m-d H:i:s')];
    }
}
