<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure;

use support\Db;

/** 批量投影生命周期和续费订单，不返回任务上下文或租约。 */
final class LifecycleStatusProjection
{
    /**
     * @param list<array<string,mixed>> $rows 服务列表
     * @return list<array<string,mixed>> 带公开生命周期状态
     */
    public static function enrich(array $rows): array
    {
        $ids = array_column($rows, 'id');
        $tasks = Db::table('service_lifecycle_tasks as t')->leftJoin('orders as o', 'o.id', '=', 't.order_id')
            ->whereIn('t.service_id', $ids)->whereIn('t.action', ['renew', 'suspend', 'resume', 'power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'])->where('t.status', '<>', 'cancelled')
            ->where(function ($q): void {
                $q->whereNull('t.order_id')->orWhereNotIn('o.status', ['cancelled', 'refunded']);
            })->orderBy('t.id')->get(['t.service_id', 't.order_id', 't.action', 't.status', 't.error_code']);
        $states = \app\modules\service_instance\infrastructure\model\ServiceInstanceModel::query()->whereIn('id', $ids)
            ->get(['id', 'power_status', 'power_checked_at', 'installed_image', 'initial_password_disabled_at'])->keyBy('id');
        $byService = [];
        $renewals = [];
        foreach ($tasks as $task) {
            $byService[(int) $task->service_id] = $task;
            if ($task->action === 'renew' && $task->status !== 'succeeded') {
                $renewals[(int) $task->service_id] = $task;
            }
        }
        return array_map(static function (array $row) use ($byService, $renewals, $states): array {
            $task = $byService[$row['id']] ?? null;
            $renewal = $renewals[$row['id']] ?? null;
            $state = $states->get($row['id']);
            return $row + ['power_status' => $state?->power_status, 'power_checked_at' => $state?->power_checked_at,
                'installed_image' => $state?->installed_image === null ? null : json_decode($state->getRawOriginal('installed_image'), true),
                'initial_password_available' => $state?->initial_password_disabled_at === null && $row['status'] === 'active'
                    && ($row['provisioning_status'] ?? null) === 'succeeded'
                    && $row['activated_at'] !== null && strtotime($row['activated_at']) >= time() - 172800,
                'lifecycle_action' => $task?->action, 'lifecycle_status' => $task?->status,
                'lifecycle_error' => $task?->error_code, 'renewal_order_id' => $renewal?->order_id === null ? null : (int) $renewal->order_id,
                'renewal_payment_pending' => $renewal?->status === 'unpaid',
                'can_renew' => ($row['provisioning_status'] ?? null) === 'succeeded' && $renewal === null && in_array($row['status'], ['active', 'expired'], true) && $row['expires_at'] !== null
                    && ($task === null || in_array($task->status, ['succeeded', 'failed'], true) || $task->action === 'suspend')];
        }, $rows);
    }
}
