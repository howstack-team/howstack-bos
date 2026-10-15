<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\infrastructure;

use app\exception\BusinessException;
use app\modules\official_platform\infrastructure\PrivateInstallationFiles;
use app\modules\software_maintenance\contract\InstallationLockInterface;
use app\shared\http\ApiErrorCode;

/** 非阻塞实例文件锁；同一实例所有Worker共享私有持久目录。 */
final readonly class FileInstallationLock implements InstallationLockInterface
{
    /** @param string $directory 私有持久目录 */
    public function __construct(private string $directory)
    {
    }

    /** @inheritDoc */
    public function synchronized(callable $operation): mixed
    {
        PrivateInstallationFiles::ensureDirectory($this->directory);
        $path = $this->directory . '/installation.lock';
        if (is_link($path)) {
            throw new \RuntimeException('安装锁不允许符号链接');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('无法打开安装锁');
        }
        chmod($path, 0600);
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new BusinessException('正在执行安装或更新，请稍后重试', ApiErrorCode::CONFLICT, 409);
            }
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
