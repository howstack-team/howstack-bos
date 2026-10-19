<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\modules\order\contract\MemberOrderRepositoryInterface;
use app\modules\order\infrastructure\model\{OrderModel, OrderItemModel};

/** 在查询条件中约束归属，并按客户展示白名单选择列。 */
final class DatabaseMemberOrderRepository implements MemberOrderRepositoryInterface
{
    private const array COLUMNS = ['id', 'order_no', 'order_type', 'status', 'payment_status', 'currency', 'payable_minor', 'paid_minor', 'created_at'];

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
        $order['units'] = \support\Db::table('order_item_units as u')->join('order_items as i', 'i.id', '=', 'u.order_item_id')
            ->leftJoin('service_instances as s', 's.id', '=', 'u.service_id')->leftJoin('service_provisioning_tasks as p', 'p.order_unit_id', '=', 'u.id')
            ->where('i.order_id', $id)->orderBy('u.id')->get(['u.id', 'u.unit_index', 'u.service_id', 's.instance_no', 's.status', 'p.status as provisioning_status'])
            ->map(static fn (object $r): array => ['id' => (int) $r->id, 'unit_index' => (int) $r->unit_index, 'service_id' => $r->service_id === null ? null : (int) $r->service_id,
                'instance_no' => $r->instance_no, 'status' => $r->status, 'provisioning_status' => $r->provisioning_status])->all();
        $order['renewal'] = \support\Db::table('service_lifecycle_tasks')->where('order_id', $id)->first(['service_id', 'months', 'expected_expires_at', 'target_expires_at', 'status']);
        if ($order['renewal'] !== null) $order['renewal'] = (array) $order['renewal'];
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
