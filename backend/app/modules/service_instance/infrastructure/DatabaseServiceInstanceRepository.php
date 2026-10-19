<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure;

use app\shared\infrastructure\TableQueryOrder;

use app\modules\service_instance\contract\ServiceInstanceRepositoryInterface;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/** 从公共服务表查询实例及交付状态，不把付款成功当作已交付。 */
final class DatabaseServiceInstanceRepository implements ServiceInstanceRepositoryInterface
{
    private const array COLUMNS = [
        's.id', 's.instance_no', 's.product_type', 's.member_id', 's.display_name',
        's.status', 's.activated_at', 's.expires_at', 's.created_at',
        'm.nickname as member_nickname', 'm.mobile as member_mobile',
        'o.order_no as order_no', 'p.status as provisioning_status', 'p.attempts as provisioning_attempts', 'p.error_code as provisioning_error', 'p.external_id as provisioning_external_id', 'p.addresses', 'p.not_created_reference', 'p.not_created_reason', 'p.not_created_by', 'p.not_created_at',
    ];
    /** @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合 */
    public function paginate(
        string $productType,
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
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

        foreach ([
            'instance_no' => 's.instance_no',
            'display_name' => 's.display_name',
            'member_mobile' => 'm.mobile',
            'member_nickname' => 'm.nickname',
        ] as $field => $column) {
            if (isset($filters[$field])) {
                $pattern = '%' . strtr($filters[$field], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $query->whereRaw($query->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        $total = (int) (clone $query)->count('s.id');
        $rows = TableQueryOrder::apply($query, $filters, [
                'created_at' => 's.created_at',
                'activated_at' => 's.activated_at',
                'expires_at' => 's.expires_at',
            ], ['s.id' => 'desc'], 's.id')->select(self::COLUMNS)->forPage($page, $pageSize)->get();

        return ['data' => LifecycleStatusProjection::enrich(array_map(self::normalize(...), $rows->all())), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $row = $this->query()->where('s.id', $id)->select(self::COLUMNS)->first();

        return $row instanceof stdClass ? LifecycleStatusProjection::enrich([self::normalize($row)])[0] : null;
    }

    private function query(): Builder
    {
        return Db::table('service_instances as s')
            ->leftJoin('service_provisioning_tasks as p', 'p.service_id', '=', 's.id')
            ->join('members as m', 'm.id', '=', 's.member_id')
            ->leftJoin('order_item_units as u', 'u.id', '=', 's.origin_order_unit_id')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'u.order_item_id')
            ->leftJoin('orders as o', 'o.id', '=', 'oi.order_id');
    }

    /** @return array<string, mixed> */
    private static function normalize(stdClass $row): array
    {
        $data = (array) $row;
        foreach (['id', 'member_id', 'not_created_by'] as $field) {
            $data[$field] = $data[$field] === null ? null : (int) $data[$field];
        }

        $data['provisioning_attempts'] = $data['provisioning_attempts'] === null ? null : (int) $data['provisioning_attempts'];
        $data['can_confirm_not_created'] = $data['provisioning_status'] === 'attention' && $data['provisioning_external_id'] === null;
        unset($data['provisioning_external_id']);
        $data['addresses'] = json_decode($data['addresses'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        return $data;
    }
}
