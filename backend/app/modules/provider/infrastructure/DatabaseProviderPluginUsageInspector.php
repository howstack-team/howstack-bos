<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure;

use app\modules\plugin\contract\PluginUsageInspectorInterface;
use app\modules\provider\infrastructure\model\ProviderConnectionModel;

/** 提供者域对外提供自己的只读引用统计，不泄漏连接配置。 */
final class DatabaseProviderPluginUsageInspector implements PluginUsageInspectorInterface
{
    /** @inheritDoc */
    public function inspect(string $id): array
    {
        $counts = ProviderConnectionModel::query()->where('adapter_id', $id)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS active')->first();
        $tasks = \support\Db::table('service_provisioning_tasks as p')->join('service_instances as s', 's.id', '=', 'p.service_id')->where('p.adapter_id', $id);
        $total = (clone $tasks)->count('p.id');
        $active = (clone $tasks)->where(function ($q): void { $q->whereIn('p.status', ['queued', 'processing', 'waiting', 'attention'])->orWhereIn('s.status', ['active', 'suspended', 'expired']); })->count('p.id');
        return ['references' => (int) $counts->total + $total, 'active_references' => (int) $counts->active + $active];
    }
}
