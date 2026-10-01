<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure;

use app\modules\order\infrastructure\model\OrderModel;
use app\modules\order\infrastructure\model\OrderItemModel;
use support\Model;
use app\modules\order\contract\OrderRepositoryInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use RuntimeException;
use Throwable;

/** 从订单快照查询后台列表及订单项，不回显原始配置快照。 */
final class DatabaseOrderRepository implements OrderRepositoryInterface
{
    private const array ORDER_COLUMNS = [
        'id', 'order_no', 'member_id', 'member_mobile_snapshot', 'member_nickname_snapshot',
        'order_type', 'status', 'payment_status', 'currency', 'subtotal_minor', 'discount_minor',
        'payable_minor', 'paid_minor', 'refunded_minor', 'remark', 'paid_at', 'completed_at',
        'cancelled_at', 'created_at', 'updated_at',
    ];

    public function paginate(string $keyword, ?string $status, int $page, int $pageSize): array
    {
        try {
            $query = OrderModel::query();
            if ($keyword !== '') {
                $query->where(static function (EloquentBuilder $nested) use ($keyword): void {
                    $pattern = '%' . $keyword . '%';
                    $nested->where('order_no', 'like', $pattern)
                        ->orWhere('member_mobile_snapshot', 'like', $pattern)
                        ->orWhere('member_nickname_snapshot', 'like', $pattern);
                });
            }
            if ($status !== null) {
                $query->where('status', $status);
            }
            $total = (int) (clone $query)->count();
            $rows = $query->select(self::ORDER_COLUMNS)->orderBy('id', 'desc')->forPage($page, $pageSize)->get();

            return ['data' => array_map(self::normalizeOrder(...), $rows->all()), 'total' => $total];
        } catch (Throwable $exception) {
            throw new RuntimeException('订单列表查询失败', 0, $exception);
        }
    }

    public function findWithItems(int $id): ?array
    {
        try {
            $row = OrderModel::query()->select(self::ORDER_COLUMNS)->where('id', $id)->first();
            if (!$row instanceof Model) {
                return null;
            }
            $items = OrderItemModel::query()->where('order_id', $id)->orderBy('line_no')->get([
                'id', 'line_no', 'product_type', 'product_type_version', 'sales_version',
                'product_name', 'quantity', 'unit_price_minor', 'total_minor',
            ]);
            $order = self::normalizeOrder($row);
            $order['items'] = array_map(static function (Model $item): array {
                $data = $item->getAttributes();
                foreach (['id', 'line_no', 'quantity', 'unit_price_minor', 'total_minor'] as $field) {
                    $data[$field] = (int) $data[$field];
                }
                return $data;
            }, $items->all());

            return $order;
        } catch (Throwable $exception) {
            throw new RuntimeException('订单详情查询失败', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private static function normalizeOrder(Model $row): array
    {
        $data = $row->getAttributes();
        foreach (['id', 'member_id', 'subtotal_minor', 'discount_minor', 'payable_minor', 'paid_minor', 'refunded_minor'] as $field) {
            $data[$field] = (int) $data[$field];
        }

        return $data;
    }
}
