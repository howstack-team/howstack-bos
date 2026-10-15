<?php

declare(strict_types=1);

namespace app\modules\website\contract;

/** 主题压缩包的受限解包与临时文件清理。 */
interface ThemePackageInstallerInterface
{
    /** @return array<string, mixed> 已校验包，尚未登记或启用 */
    public function unpack(string $archive): array;
    /** 清除本次安装专属暂存目录，不删除已发布快照。 */
    public function discard(array $package): void;
}
