<?php

declare(strict_types=1);

namespace app\modules\member\domain;

/** 新编号固定九位随机数字；读取兼容历史八位编号，不包含注册顺序。 */
final class PublicMemberId
{
    public static function generate(): string
    {
        return (string) random_int(100000000, 999999999);
    }

    public static function valid(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{7,8}$/D', $value) === 1;
    }
}
