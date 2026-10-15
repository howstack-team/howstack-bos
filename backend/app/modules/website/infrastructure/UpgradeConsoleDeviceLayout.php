<?php

declare(strict_types=1);
namespace app\modules\website\infrastructure;

/** 前向迁移专用：将旧自适应入口转为PC，已有PC入口优先，旧快照保持不变。 */
final class UpgradeConsoleDeviceLayout
{
    public function convert(array $package, string $root): void
    {
        $target = $root . '/' . $package['manifest']['id'];
        $source = $package['directory'];
        $hasPc = isset($package['console']['pc']);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = substr($file->getPathname(), strlen($source) + 1);
            if (str_starts_with($name, 'template/')) {
                $name = 'website/' . substr($name, strlen('template/'));
            }
            if (str_starts_with($name, 'console/responsive/')) {
                if ($hasPc) continue;
                $name = 'console/pc/' . substr($name, strlen('console/responsive/'));
            }
            $path = $target . '/' . $name;
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0750, true)) throw new \RuntimeException('无法创建主题迁移目录');
            if (!copy($file->getPathname(), $path)) throw new \RuntimeException('无法迁移主题文件');
        }
    }
}
