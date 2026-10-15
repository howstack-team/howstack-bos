<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure;

use app\modules\channel\infrastructure\model\ChannelConnectionModel;
use app\modules\plugin\contract\PluginUsageInspectorInterface;

/** 已配置通道参与插件引用保护；归档配置仍保留历史引用。 */
final class DatabaseChannelPluginUsageInspector implements PluginUsageInspectorInterface
{
    /** @inheritDoc */
    public function inspect(string $id): array
    {
        $counts = ChannelConnectionModel::query()->where('plugin_id', $id)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 1 AND deleted_at IS NULL THEN 1 ELSE 0 END), 0) AS active')
            ->first();
        return ['references' => (int) $counts->total, 'active_references' => (int) $counts->active];
    }
}
