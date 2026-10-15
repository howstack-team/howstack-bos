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
        return ['references' => (int) $counts->total, 'active_references' => (int) $counts->active];
    }
}
