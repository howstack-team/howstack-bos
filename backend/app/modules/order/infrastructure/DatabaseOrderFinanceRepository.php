<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\modules\order\contract\OrderFinanceRepositoryInterface;
use app\modules\order\infrastructure\model\OrderModel;
use app\modules\order\infrastructure\model\OrderItemModel;
use app\modules\order\infrastructure\model\OrderItemUnitModel;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use LogicException;

final class DatabaseOrderFinanceRepository implements OrderFinanceRepositoryInterface
{
    public function __construct(private readonly \app\modules\product\contract\ProductOrderCapacityInterface $capacity) {}

    public function orderId(int $unitId): int
    {
        $itemId = OrderItemUnitModel::query()->whereKey($unitId)->value('order_item_id');
        return (int) OrderItemModel::query()->whereKey($itemId)->value('order_id');
    }

    public function lock(int $orderId): array
    {
        $this->requireTransaction();
        $order = OrderModel::query()->whereKey($orderId)->lockForUpdate()->first();
        if ($order === null) {
            throw new BusinessException('订单不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        $itemIds = OrderItemModel::query()->where('order_id', $orderId)->pluck('id');
        $units = OrderItemUnitModel::query()->whereIn('order_item_id', $itemIds)->orderBy('id')
            ->lockForUpdate()->get(['id', 'service_id', 'allocated_minor', 'refunded_minor', 'reserved_refund_minor']);
        return [
            'id' => $order->id, 'member_id' => $order->member_id, 'currency' => $order->currency,
            'payment_status' => $order->payment_status, 'paid_minor' => $order->paid_minor,
            'refunded_minor' => $order->refunded_minor,
            'units' => $units->map(static fn (OrderItemUnitModel $unit): array => [
                'id' => $unit->id, 'service_id' => $unit->service_id,
                'allocated_minor' => $unit->allocated_minor, 'refunded_minor' => $unit->refunded_minor,
                'reserved_refund_minor' => $unit->reserved_refund_minor,
            ])->all(),
        ];
    }

    public function reserve(int $unitId, int $amount): void
    {
        $this->requireTransaction();
        OrderItemUnitModel::query()->whereKey($unitId)->increment('reserved_refund_minor', $amount);
    }

    public function release(int $unitId, int $amount): void
    {
        $this->requireTransaction();
        $updated = OrderItemUnitModel::query()->whereKey($unitId)->where('reserved_refund_minor', '>=', $amount)
            ->decrement('reserved_refund_minor', $amount);
        if ($updated !== 1) {
            throw new LogicException('退款预留金额不一致');
        }
    }

    public function complete(int $orderId, int $unitId, int $amount): void
    {
        $this->release($unitId, $amount);
        OrderItemUnitModel::query()->whereKey($unitId)->increment('refunded_minor', $amount);
        $order = OrderModel::query()->whereKey($orderId)->firstOrFail();
        $refunded = $order->refunded_minor + $amount;
        if ($refunded > $order->paid_minor) {
            throw new LogicException('退款总额超过订单实付');
        }
        $values = ['refunded_minor' => $refunded,
            'payment_status' => $refunded === $order->paid_minor ? 'refunded' : 'partial_refund'];
        if ($refunded === $order->paid_minor) {
            $values['status'] = 'refunded';
        }
        $order->fill($values)->save();
        $unit = OrderItemUnitModel::query()->findOrFail($unitId);
        if ($unit->refunded_minor === $unit->allocated_minor) {
            $item = OrderItemModel::query()->findOrFail($unit->order_item_id);
            $snapshot = json_decode($item->configuration_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (($snapshot['checkout_version'] ?? null) === 1) {
                $this->capacity->release($item->product_type, $unitId);
            }
        }
    }

    private function requireTransaction(): void
    {
        if ((new OrderModel())->getConnection()->transactionLevel() < 1) {
            throw new LogicException('订单财务变更必须在默认连接事务中执行');
        }
    }
}
