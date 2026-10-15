<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

/** 前向迁移专用：复制已校验的旧快照，只将官网目录改为 website，保留旧资源。 */
final readonly class UpgradeWebsiteDirectory
{
    /**
     * @param array<string, mixed> $package 已通过摘要和内容校验的旧主题包
     * @param string $root 本次迁移独占的临时目录
     */
    public function convert(array $package, string $root): void
    {
        $source = $package['directory'];
        $target = $root . '/' . $package['manifest']['id'];
        if (!is_dir($source . '/template') || is_dir($source . '/website')) {
            throw new \RuntimeException('待迁移主题的官网目录无效');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = substr($file->getPathname(), strlen($source) + 1);
            if (str_starts_with($name, 'template/')) {
                $name = 'website/' . substr($name, strlen('template/'));
            }
            $path = $target . '/' . $name;
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0750, true)) {
                throw new \RuntimeException('无法创建主题官网迁移目录');
            }
            if (!copy($file->getPathname(), $path)) {
                throw new \RuntimeException('无法迁移主题官网文件');
            }
        }
    }
}
