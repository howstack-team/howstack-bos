<?php

declare(strict_types=1);
namespace app\modules\website\infrastructure;

/** 已执行迁移的目录契约，限定在迁移执行期间，不能用于HTTP安装新包。 */
final class HistoricalThemeLayout
{
    private static bool $active = false;

    public static function active(): bool { return self::$active; }

    public static function run(callable $migration): void
    {
        $previous = self::$active;
        self::$active = true;
        try { $migration(); }
        finally { self::$active = $previous; }
    }
}
