<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\modules\order\contract\OrderPaymentRepositoryInterface;
use app\modules\order\domain\WalletPayableOrder;
use app\modules\order\infrastructure\model\OrderModel;
use app\modules\order\infrastructure\model\OrderItemModel;
use LogicException;

/** 不跨模块写表，实例分摊随订单锁保护。 */
final class DatabaseOrderPaymentRepository implements OrderPaymentRepositoryInterface
{
    public function lock(int $orderId): ?WalletPayableOrder
    {
        $row = OrderModel::query()->whereKey($orderId)->lockForUpdate()->first();
        return $row === null ? null : new WalletPayableOrder(
            $row->id, $row->order_no, $row->member_id, $row->currency,
            $row->status, $row->payment_status, $row->payable_minor, $row->paid_minor,
        );
    }

    public function allocationIsComplete(int $orderId, int $amountMinor): bool
    {
        $items = OrderItemModel::query()->where('order_id', $orderId)
            ->with(['units' => static fn ($query) => $query->orderBy('unit_index')])->get();
        if ($items->isEmpty()) return false;
        $sum = 0;
        foreach ($items as $item) {
            if ($item->quantity < 1 || $item->units->count() !== $item->quantity) return false;
            foreach ($item->units as $index => $unit) {
                if ($unit->unit_index !== $index + 1 || $unit->allocated_minor < 0
                    || $unit->allocated_minor > PHP_INT_MAX - $sum) return false;
                $sum += $unit->allocated_minor;
            }
        }
        return $sum === $amountMinor;
    }

    public function markPaid(int $orderId, int $amountMinor, string $paidAt): void
    {
        $changed = OrderModel::query()->whereKey($orderId)->where('status', 'pending_payment')
            ->where('payment_status', 'unpaid')->where('paid_minor', 0)->update([
                'status' => 'paid', 'payment_status' => 'paid', 'paid_minor' => $amountMinor, 'paid_at' => $paidAt,
            ]);
        if ($changed !== 1) throw new LogicException('锁定订单的付款状态发生变化');
    }
}
