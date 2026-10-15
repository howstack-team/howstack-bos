<?php

declare(strict_types=1);

namespace app\modules\message\domain;

/** 聚合状态独立于单个渠道；厂商受理与最终送达严格区分。 */
final class MessageOutcome
{
    public static function aggregate(array $states): string
    {
        $total = array_sum($states);
        if ($total === 0) return 'inactive';
        if (($states['unknown'] ?? 0) > 0) return 'unknown';
        if (($states['processing'] ?? 0) > 0) return 'processing';
        if (($states['pending'] ?? 0) > 0) return 'pending';
        if (($states['delivered'] ?? 0) === $total) return 'delivered';
        $completed = ($states['accepted'] ?? 0) + ($states['delivered'] ?? 0);
        if ($completed === $total) return 'accepted';
        if ($completed > 0) return 'partial';
        return 'failed';
    }
}
