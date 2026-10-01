<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure;

use app\modules\service_instance\contract\ServiceInstanceRepositoryInterface;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/** 从公共实例表查询已创建资源，不把订单项当作已交付实例。 */
final class DatabaseServiceInstanceRepository implements ServiceInstanceRepositoryInterface
{
    private const array COLUMNS = [
        's.id', 's.instance_no', 's.product_type', 's.member_id', 's.display_name',
        's.status', 's.activated_at', 's.expires_at', 's.created_at',
        'm.nickname as member_nickname', 'm.mobile as member_mobile',
        'o.order_no as order_no',
    ];

    public function paginate(string $productType, string $keyword, ?string $status, int $page, int $pageSize): array
    {
        $query = $this->query()->where('s.product_type', $productType);
        if ($status !== null) {
            $query->where('s.status', $status);
        }
        if ($keyword !== '') {
            $pattern = '%' . $keyword . '%';
            $query->where(static function (Builder $nested) use ($pattern): void {
                $nested->where('s.instance_no', 'like', $pattern)
                    ->orWhere('s.display_name', 'like', $pattern)
                    ->orWhere('m.nickname', 'like', $pattern)
                    ->orWhere('m.mobile', 'like', $pattern);
            });
        }

        $total = (int) (clone $query)->count('s.id');
        $rows = $query->select(self::COLUMNS)->orderBy('s.id', 'desc')->forPage($page, $pageSize)->get();

        return ['data' => array_map(self::normalize(...), $rows->all()), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $row = $this->query()->where('s.id', $id)->select(self::COLUMNS)->first();

        return $row instanceof stdClass ? self::normalize($row) : null;
    }

    private function query(): Builder
    {
        return Db::table('service_instances as s')
            ->join('members as m', 'm.id', '=', 's.member_id')
            ->leftJoin('order_item_units as u', 'u.id', '=', 's.origin_order_unit_id')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'u.order_item_id')
            ->leftJoin('orders as o', 'o.id', '=', 'oi.order_id');
    }

    /** @return array<string, mixed> */
    private static function normalize(stdClass $row): array
    {
        $data = (array) $row;
        foreach (['id', 'member_id'] as $field) {
            $data[$field] = (int) $data[$field];
        }

        return $data;
    }
}
