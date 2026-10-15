<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\modules\order\contract\OrderManagementRepositoryInterface;
use app\modules\order\domain\OrderUnitAllocator;
use app\modules\order\infrastructure\model\{OrderModel, OrderItemModel, OrderItemUnitModel};
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\Db;

final class DatabaseOrderManagementRepository implements OrderManagementRepositoryInterface
{
    public function members(string $search): array
    {
        $query = Db::table('members')->where('status', 1);
        if ($search !== '') $query->where(function ($q) use ($search): void {
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $q->whereRaw("nickname LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("mobile LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]);
            if (ctype_digit($search)) $q->orWhere('public_id', $search);
        });
        return $query->orderByDesc('id')->limit(20)->get(['id', 'public_id', 'nickname'])->map(static fn ($r): array => ['id' => (int) $r->id, 'label' => $r->nickname . '（ID：' . $r->public_id . '）'])->all();
    }
    public function member(int $id): ?array
    {
        $row = Db::table('members')->where('id', $id)->first(['id','mobile','nickname','status']);
        return $row === null ? null : ['id' => (int) $row->id, 'mobile' => $row->mobile, 'nickname' => $row->nickname, 'status' => (int) $row->status];
    }
    public function begin(string $key, string $hash): ?int
    {
        Db::table('order_requests')->insertOrIgnore(['request_key' => $key, 'request_hash' => $hash, 'created_at' => date('Y-m-d H:i:s')]);
        $row = Db::table('order_requests')->where('request_key', $key)->lockForUpdate()->first();
        if (!hash_equals($row->request_hash, $hash)) $this->fail('请求标识已用于另一份订单');
        return $row->order_id === null ? null : (int) $row->order_id;
    }
    public function finish(string $key, int $orderId): void
    {
        Db::table('order_requests')->where('request_key', $key)->update(['order_id' => $orderId]);
    }
    public function create(array $member, array $catalog, array $price, array $provisioning, string $remark): int
    {
        $subtotal = $price['subtotal_minor'] ?? $price['amount_minor'];
        $order = OrderModel::query()->create(['order_no' => 'OR' . bin2hex(random_bytes(16)), 'member_id' => $member['id'],
            'member_mobile_snapshot' => $member['mobile'] ?? '', 'member_nickname_snapshot' => $member['nickname'],
            'order_type' => 'purchase', 'status' => 'pending_payment', 'payment_status' => 'unpaid', 'currency' => $price['currency'],
            'subtotal_minor' => $subtotal, 'discount_minor' => $subtotal - $price['amount_minor'], 'payable_minor' => $price['amount_minor'], 'remark' => $remark]);
        $snapshot = ['checkout_version' => 1, 'offer_id' => $catalog['offer_id'], 'version_id' => $catalog['version_id'],
            'configuration' => $price['configuration'], 'price' => $price, 'provisioning' => $provisioning,
            'delivery_binding' => $catalog['snapshot']['delivery_binding'] ?? [],
            'policies' => array_intersect_key($catalog['snapshot'], array_flip(['refund_policy','renewal_policy','trial_policy']))];
        $item = OrderItemModel::query()->create(['order_id' => $order->id, 'line_no' => 1, 'product_type' => $catalog['product_type'],
            'product_type_version' => $catalog['type_version'], 'sales_version' => (string) $catalog['version_id'], 'product_name' => $catalog['product_name'],
            'configuration_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'quantity' => $price['quantity'],
            'unit_price_minor' => intdiv($subtotal, $price['quantity']), 'total_minor' => $subtotal, 'created_at' => date('Y-m-d H:i:s')]);
        foreach ((new OrderUnitAllocator())->allocate([['item_id' => $item->id, 'quantity' => $price['quantity'], 'total_minor' => $subtotal]], $price['amount_minor']) as $unit) {
            OrderItemUnitModel::query()->create(['order_item_id' => $item->id, 'unit_index' => $unit['unit_index'], 'allocated_minor' => $unit['allocated_minor'], 'created_at' => date('Y-m-d H:i:s')]);
        }
        return $order->id;
    }
    public function lock(int $id): array
    {
        $row = OrderModel::query()->whereKey($id)->lockForUpdate()->first();
        if (!$row) throw new BusinessException('订单不存在', ApiErrorCode::NOT_FOUND, 404);
        return ['id' => $row->id, 'order_no' => $row->order_no, 'member_id' => $row->member_id, 'status' => $row->status, 'payment_status' => $row->payment_status,
            'paid_minor' => $row->paid_minor, 'payable_minor' => $row->payable_minor, 'currency' => $row->currency, 'remark' => $row->remark];
    }
    public function cancel(int $id): void
    {
        OrderModel::query()->whereKey($id)->update(['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')]);
    }
    public function remark(int $id, string $remark, string $expected): void
    {
        $row = OrderModel::query()->findOrFail($id);
        if ($row->remark !== $expected) $this->fail('备注已被修改，请刷新后重试');
        $row->fill(['remark' => $remark])->save();
    }
    private function fail(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
