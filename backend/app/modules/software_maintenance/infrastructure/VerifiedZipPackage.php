<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\infrastructure;

use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\official_platform\infrastructure\PrivateInstallationFiles;
use ZipArchive;

/** 逐文件解包，拒绝目录穿越、链接、重复路径与过度解压；不执行包内脚本。 */
final class VerifiedZipPackage
{
    /**
     * @param string $archive 已核对外层签名摘要的ZIP
     * @param string $directory 新建私有临时目录
     * @return list<string> 普通文件路径
     */
    public function extract(string $archive, string $directory): array
    {
        if (!class_exists(ZipArchive::class) || file_exists($directory) || is_link($directory)) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
        }
        PrivateInstallationFiles::ensureDirectory($directory);
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
        }
        try {
            if ($zip->numFiles > 20000) {
                throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
            }
            $seen = [];
            $files = [];
            $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'];
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (!is_string($name) || strlen($name) > 512 || str_contains($name, '\\') || str_contains($name, "\0")
                    || str_starts_with($name, '/') || str_contains($name, ':')
                    || preg_match('~(^|/)(\.|\.\.)(/|$)|//~', $name) || isset($seen[$name])
                    || ($type !== 0 && !in_array($type, [0100000, 0040000], true))
                    || ($entry['encryption_method'] ?? 0) !== 0) {
                    throw new OfficialPlatformException('UNSAFE_PACKAGE_PATH');
                }
                $seen[$name] = true;
                $size += $entry['size'];
                if ($size > 1073741824 || $entry['size'] > 134217728) {
                    throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $path = $directory . '/' . $name;
                PrivateInstallationFiles::ensureDirectory(dirname($path));
                $input = $zip->getStream($name);
                $output = fopen($path, 'x');
                if ($input === false || $output === false) {
                    throw new OfficialPlatformException('STATE_IO_ERROR');
                }
                chmod($path, 0600);
                try {
                    $copied = stream_copy_to_stream($input, $output, $entry['size'] + 1);
                    if ($copied !== $entry['size']) {
                        throw new OfficialPlatformException('PACKAGE_INTEGRITY_ERROR');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                $files[] = $name;
            }
            return $files;
        } finally {
            $zip->close();
        }
    }
}
