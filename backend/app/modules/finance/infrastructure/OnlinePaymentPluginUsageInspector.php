<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\modules\finance\infrastructure\model\{OnlinePaymentAttemptModel, PaymentTransactionModel};
use app\modules\plugin\contract\PluginUsageInspectorInterface;
use support\Db;

/** 支付快照保护原插件；七日通知窗口内不能换包或卸载。 */
final class OnlinePaymentPluginUsageInspector implements PluginUsageInspectorInterface
{
    public function inspect(string $id): array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('online_payment_attempts')) return ['references' => 0, 'active_references' => 0];
        return ['references' => OnlinePaymentAttemptModel::query()->where('plugin_id', $id)->count(),
            'active_references' => OnlinePaymentAttemptModel::query()->where('plugin_id', $id)
                ->where(fn ($q) => $q->whereIn('payment_id', PaymentTransactionModel::query()->select('id')->whereIn('status', ['creating', 'pending', 'unknown']))->orWhere('reconcile_until', '>', date('Y-m-d H:i:s')))->count()];
    }
}
