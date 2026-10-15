<?php

declare(strict_types=1);

namespace app\modules\message\domain;

/** 固定场景元数据；通知分类与用途分别建模，不执行任意 URL。 */
final class MessageScene
{
    /** @return array{purpose: string, category: string, topic: string} */
    public static function metadata(string $scene): array
    {
        [$category, $topic] = match ($scene) {
            'order_created' => ['trade', 'new-order'], 'order_paid' => ['trade', 'paid-order'],
            'order_cancelled', 'renewal_succeeded', 'renewal_failed' => ['trade', 'renewal'],
            'service_activated', 'delivery_failed' => ['business', 'delivery'],
            'service_due', 'service_expired', 'service_suspended' => ['business', 'expiry'],
            'ticket_created', 'ticket_replied' => ['service', 'ticket'],
            'real_name_pending', 'real_name_approved', 'real_name_rejected' => ['service', 'authentication'],
            'recharge_received' => ['finance', 'recharge'], 'refund_pending', 'refund_completed' => ['finance', 'refund'],
            'invoice_pending', 'invoice_issued' => ['finance', 'invoice'],
            'system_update' => ['system', 'update'], default => ['system', 'announcement'],
        };
        return ['purpose' => in_array($scene, ['register_verification', 'login_verification', 'password_reset'], true)
            ? 'verification' : ($scene === 'system_update' ? 'system' : 'notification'), 'category' => $category, 'topic' => $topic];
    }

    /** 仅替换声明的简单变量，不解析代码或递归模板。 */
    public static function render(string $template, array $variables): string
    {
        return preg_replace_callback('/\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}/', static fn (array $match): string =>
            (string) ($variables[$match[1]] ?? ''), $template);
    }
}
