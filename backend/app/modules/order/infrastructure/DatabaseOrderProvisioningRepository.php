<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\exception\BusinessException;
use app\modules\order\contract\OrderProvisioningRepositoryInterface;
use app\modules\order\infrastructure\model\{OrderModel, OrderItemModel, OrderItemUnitModel};
use app\shared\http\ApiErrorCode;

/** 统一锁序为订单、订单项、实例单位；外部网络调用前提交事务。 */
final class DatabaseOrderProvisioningRepository implements OrderProvisioningRepositoryInterface
{
    /** @inheritDoc */
    public function lock(int $orderId): array
    {
        $order = OrderModel::query()->whereKey($orderId)->lockForUpdate()->first()
            ?? throw new BusinessException('订单不存在', ApiErrorCode::NOT_FOUND, 404);
        $items = OrderItemModel::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
        $units = [];
        foreach ($items as $item) {
            $snapshot = json_decode($item->configuration_snapshot, true, flags: JSON_THROW_ON_ERROR);
            foreach (OrderItemUnitModel::query()->where('order_item_id', $item->id)->orderBy('id')->lockForUpdate()->get() as $unit) {
                $units[] = $unit->getAttributes() + ['product_type' => $item->product_type, 'product_name' => $item->product_name, 'snapshot' => $snapshot];
            }
        }
        return $order->getAttributes() + ['units' => $units];
    }
    /** @inheritDoc */
    public function link(int $unitId, int $serviceId): void
    {
        $unit = OrderItemUnitModel::query()->findOrFail($unitId);
        if ($unit->service_id !== null && $unit->service_id !== $serviceId) throw new BusinessException('订单实例已关联其他服务', ApiErrorCode::CONFLICT, 409);
        $unit->service_id = $serviceId;
        $unit->save();
    }
    /** @inheritDoc */
    public function status(int $orderId, string $status): void
    {
        OrderModel::query()->whereKey($orderId)->whereIn('payment_status', ['paid', 'partial_refund'])->whereIn('status', ['paid', 'fulfilling', 'completed', 'failed'])
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
