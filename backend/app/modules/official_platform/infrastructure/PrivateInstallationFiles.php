<?php

declare(strict_types=1);

namespace app\modules\official_platform\infrastructure;

use app\modules\official_platform\exception\OfficialPlatformException;

/** 仅用于固定的后端私有目录，不接收浏览器提交的路径。 */
final class PrivateInstallationFiles
{
    /**
     * @param string $directory 私有绝对目录
     * @return void
     */
    public static function ensureDirectory(string $directory): void
    {
        if (!str_starts_with($directory, '/') || $directory === '/'
            || preg_match('~(^|/)(\.|\.\.)(/|$)|//~', $directory)) {
            throw new OfficialPlatformException('INVALID_CONFIGURATION');
        }
        for ($current = $directory; $current !== '/'; $current = dirname($current)) {
            if (is_link($current)) {
                throw new OfficialPlatformException('INVALID_CONFIGURATION');
            }
        }
        $public = rtrim(base_path('public'), '/');
        if ($directory === $public || str_starts_with($directory, $public . '/')) {
            throw new OfficialPlatformException('INVALID_CONFIGURATION');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
        chmod($directory, 0700);
    }

    /**
     * @param string $path 固定私有文件
     * @param string $bytes 文件内容
     * @return void 原子替换，不输出内容
     */
    public static function write(string $path, string $bytes): void
    {
        self::ensureDirectory(dirname($path));
        if (is_link($path)) {
            throw new OfficialPlatformException('INVALID_CONFIGURATION');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
        chmod($temporary, 0600);
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new OfficialPlatformException('STATE_IO_ERROR');
            }
            if (function_exists('fsync')) {
                fsync($handle);
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $path)) {
                throw new OfficialPlatformException('STATE_IO_ERROR');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
