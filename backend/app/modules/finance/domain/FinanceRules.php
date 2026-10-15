<?php

declare(strict_types=1);

namespace app\modules\finance\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 金额上限与单据状态规则；全程使用整数分。 */
final class FinanceRules
{
    public static function amount(int $amount, int $available): void
    {
        if ($amount < 1 || $amount > $available || $amount > 100000000) {
            self::conflict('金额必须大于零且不能超过可处理金额');
        }
    }

    public static function state(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            self::conflict('单据状态已变化，请刷新后再操作');
        }
    }

    public static function conflict(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
