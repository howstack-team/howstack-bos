<?php

declare(strict_types=1);

namespace app\modules\finance\domain;

/** 已付款为吸收态；本地过期和网络未知不能推断未收款。 */
final class OnlinePaymentState
{
    public static function next(string $current, string $result, bool $creating): string
    {
        if ($current === 'paid' || $result === 'paid') return 'paid';
        if (in_array($current, ['closed', 'failed'], true)) return $current;
        return match ($result) { 'pending' => 'pending', 'closed' => 'closed', 'failed' => $creating ? 'failed' : 'unknown', default => 'unknown' };
    }
}
