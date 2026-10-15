<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\modules\order\contract\MemberOrderRepositoryInterface;
use app\modules\order\infrastructure\model\{OrderModel, OrderItemModel};

/** 在查询条件中约束归属，并按客户展示白名单选择列。 */
final class DatabaseMemberOrderRepository implements MemberOrderRepositoryInterface
{
    private const array COLUMNS = ['id', 'order_no', 'status', 'payment_status', 'currency', 'payable_minor', 'paid_minor', 'created_at'];

    /** @inheritDoc */
    public function paginate(int $memberId, int $page, int $size): array
    {
        $query = OrderModel::query()->where('member_id', $memberId);
        $total = (clone $query)->count();
        return ['data' => $query->orderByDesc('id')->forPage($page, $size)->get(self::COLUMNS)->map($this->map(...))->all(), 'total' => $total];
    }

    /** @inheritDoc */
    public function find(int $memberId, int $id): ?array
    {
        $row = OrderModel::query()->where('member_id', $memberId)->whereKey($id)->first(self::COLUMNS);
        if (!$row) return null;
        $order = $this->map($row);
        $order['items'] = OrderItemModel::query()->where('order_id', $id)->orderBy('line_no')->get(['product_name', 'quantity', 'total_minor'])->map(
            static fn (OrderItemModel $item): array => ['product_name' => $item->product_name, 'quantity' => $item->quantity, 'total_minor' => $item->total_minor])->all();
        return $order;
    }

    /** @param OrderModel $row 白名单订单 @return array<string,mixed> 客户订单 */
    private function map(OrderModel $row): array
    {
        $data = $row->getAttributes();
        foreach (['id', 'payable_minor', 'paid_minor'] as $key) $data[$key] = (int) $data[$key];
        return $data;
    }
}
