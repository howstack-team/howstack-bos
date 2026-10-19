<?php

declare(strict_types=1);
namespace app\modules\service_instance\infrastructure;
use app\modules\service_instance\contract\MemberServiceRepositoryInterface;
use support\Db;

/** 专用跨模块只读投影；服务创建与状态仍由服务域写入。 */
final class DatabaseMemberServiceRepository implements MemberServiceRepositoryInterface
{
    private const array COLUMNS = ['s.id', 's.instance_no', 's.display_name', 's.status', 's.activated_at', 's.expires_at', 's.created_at',
        'p.status as provisioning_status', 'p.addresses', 'o.order_no', 'o.id as order_id'];
    /** @inheritDoc */
    public function page(int $memberId, int $page, int $size): array
    {
        $q = $this->query($memberId);
        $total = (clone $q)->count('s.id');
        return ['data' => LifecycleStatusProjection::enrich($q->orderByDesc('s.id')->forPage($page, $size)->get(self::COLUMNS)->map($this->map(...))->all()), 'total' => $total];
    }
    /** @inheritDoc */
    public function find(int $memberId, int $serviceId): ?array
    {
        $row = $this->query($memberId)->where('s.id', $serviceId)->first(self::COLUMNS);
        return $row ? LifecycleStatusProjection::enrich([$this->map($row)])[0] : null;
    }
    /** @param int $memberId 本人 @return \Illuminate\Database\Query\Builder 受归属限制的查询 */
    private function query(int $memberId): \Illuminate\Database\Query\Builder
    {
        return Db::table('service_instances as s')->leftJoin('service_provisioning_tasks as p', 'p.service_id', '=', 's.id')
            ->leftJoin('order_item_units as u', 'u.id', '=', 's.origin_order_unit_id')->leftJoin('order_items as i', 'i.id', '=', 'u.order_item_id')
            ->leftJoin('orders as o', 'o.id', '=', 'i.order_id')->where('s.member_id', $memberId)->where('s.product_type', 'cloud_server');
    }
    /** @param object $row 白名单数据库行 @return array<string,mixed> 会员展示资料 */
    private function map(object $row): array
    {
        $data = (array) $row;
        $data['id'] = (int) $data['id']; $data['order_id'] = $data['order_id'] === null ? null : (int) $data['order_id'];
        $data['addresses'] = json_decode($data['addresses'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        return $data;
    }
}
