<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\exception\BusinessException;
use app\modules\order\contract\{RenewalOrderRepositoryInterface, OrderManagementRepositoryInterface};
use app\modules\order\infrastructure\model\{OrderModel, OrderItemModel, OrderItemUnitModel};
use app\shared\http\ApiErrorCode;

/** 单实例续费也是普通订单和金额单元，付款、账务、取消复用现有入口。 */
final readonly class DatabaseRenewalOrderRepository implements RenewalOrderRepositoryInterface
{
    /** @param OrderManagementRepositoryInterface $orders 共用请求幂等和会员读取 */
    public function __construct(private OrderManagementRepositoryInterface $orders)
    {
    }

    /** @inheritDoc */
    public function begin(string $key, string $hash): ?int
    {
        return $this->orders->begin($key, $hash);
    }

    /** @inheritDoc */
    public function finish(string $key, int $id): void
    {
        $this->orders->finish($key, $id);
    }

    /** @inheritDoc */
    public function state(int $id): string
    {
        return OrderModel::query()->whereKey($id)->value('status') ?? 'missing';
    }

    /** @inheritDoc */
    public function create(int $memberId, int $serviceId, array $quote): int
    {
        $member = $this->orders->member($memberId);
        if (!$member || $member['status'] !== 1) {
            throw new BusinessException('会员不存在或已禁用', ApiErrorCode::CONFLICT, 409);
        }
        $price = $quote['price'];
        $sale = $quote['sale'];
        $order = OrderModel::query()->create([
            'order_no' => 'OR' . bin2hex(random_bytes(16)), 'member_id' => $memberId,
            'member_mobile_snapshot' => $member['mobile'] ?? '', 'member_nickname_snapshot' => $member['nickname'],
            'order_type' => 'renewal', 'status' => 'pending_payment', 'payment_status' => 'unpaid', 'currency' => $price['currency'],
            'subtotal_minor' => $price['subtotal_minor'], 'discount_minor' => $price['discount_minor'], 'payable_minor' => $price['amount_minor'], 'remark' => '',
        ]);
        $snapshot = ['renewal_version' => 1, 'service_id' => $serviceId, 'duration_months' => $quote['months'],
            'expected_expires_at' => $quote['expected_expires_at'], 'offer_id' => $sale['offer_id'], 'version_id' => $sale['version_id'],
            'configuration' => $price['configuration'], 'price' => $price];
        $item = OrderItemModel::query()->create([
            'order_id' => $order->id, 'line_no' => 1, 'product_type' => $sale['product_type'], 'product_type_version' => $sale['type_version'],
            'sales_version' => (string) $sale['version_id'], 'product_name' => $sale['product_name'] . '（续费）',
            'configuration_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'quantity' => 1,
            'unit_price_minor' => $price['subtotal_minor'], 'total_minor' => $price['subtotal_minor'], 'created_at' => date('Y-m-d H:i:s'),
        ]);
        OrderItemUnitModel::query()->create(['order_item_id' => $item->id, 'unit_index' => 1, 'service_id' => $serviceId,
            'allocated_minor' => $price['amount_minor'], 'created_at' => date('Y-m-d H:i:s')]);
        return $order->id;
    }
}
