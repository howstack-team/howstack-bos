<?php

declare(strict_types=1);

namespace tests\support;

use app\modules\service_instance\infrastructure\model\{ProvisioningTaskModel, ServiceInstanceModel};
use support\Db;

/** 给旧财务fixture补上真实形态的失败交付证据，不改变线上业务状态。 */
trait UndeliveredOrderFixture
{
    /** @return void 仅测试数据库的显式供应失败结论 */
    private function confirmFixtureUndelivered(int $orderId): void
    {
        $units = Db::table('order_item_units')->whereIn('order_item_id', Db::table('order_items')->where('order_id', $orderId)->select('id'))->get();
        $member = (int) Db::table('orders')->where('id', $orderId)->value('member_id');
        foreach ($units as $unit) {
            $service = ServiceInstanceModel::query()->create(['instance_no' => 'fixture-' . bin2hex(random_bytes(10)),
                'product_type' => 'cloud_server', 'member_id' => $member, 'display_name' => '失败实例', 'status' => 'terminated', 'origin_order_unit_id' => $unit->id]);
            ProvisioningTaskModel::query()->create(['order_id' => $orderId, 'order_unit_id' => $unit->id, 'service_id' => $service->id,
                'request_key' => 'fixture-task-' . bin2hex(random_bytes(10)), 'status' => 'failed', 'error_code' => 'provider_rejected',
                'next_attempt_at' => date('Y-m-d H:i:s'), 'context' => []]);
            Db::table('order_item_units')->where('id', $unit->id)->update(['service_id' => $service->id]);
        }
    }

    /** @return \app\modules\admin\auth\domain\AdminAccount 独立复核账号 */
    private function reviewer(): \app\modules\admin\auth\domain\AdminAccount
    {
        Db::table('admin_users')->insertOrIgnore(['id' => 2, 'username' => 'fixture-reviewer', 'nickname' => '测试复核人', 'password_hash' => 'hash',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return new \app\modules\admin\auth\domain\AdminAccount(2, 'fixture-reviewer', '测试复核人', 'hash', 1, null, true);
    }
}
