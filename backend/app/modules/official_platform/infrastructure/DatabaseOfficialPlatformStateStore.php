<?php

declare(strict_types=1);

namespace app\modules\official_platform\infrastructure;

use app\modules\official_platform\contract\OfficialPlatformStateStoreInterface;
use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\official_platform\infrastructure\model\OfficialPlatformStateModel;
use app\shared\contract\SensitiveDataCipherInterface;

/** 同一私有持久卷的文件锁串行更新加密授权状态。 */
final readonly class DatabaseOfficialPlatformStateStore implements OfficialPlatformStateStoreInterface
{
    /**
     * @param SensitiveDataCipherInterface $cipher APP_KEY加密
     * @param string $privateDirectory 私有持久卷
     */
    public function __construct(
        private SensitiveDataCipherInterface $cipher,
        private string $privateDirectory,
    ) {
    }

    /** @inheritDoc */
    public function read(): array
    {
        $row = OfficialPlatformStateModel::query()->find(1);
        return $row === null ? [] : json_decode($this->cipher->decrypt($row->encrypted_state), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @inheritDoc */
    public function write(array $state): void
    {
        OfficialPlatformStateModel::query()->updateOrCreate(['id' => 1], [
            'encrypted_state' => $this->cipher->encrypt(json_encode($state, JSON_THROW_ON_ERROR)),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @inheritDoc */
    public function synchronized(callable $operation): mixed
    {
        PrivateInstallationFiles::ensureDirectory($this->privateDirectory);
        $path = $this->privateDirectory . '/connection.lock';
        if (is_link($path)) {
            throw new OfficialPlatformException('INVALID_CONFIGURATION');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
        chmod($path, 0600);
        // 请求不阻塞等待其他Worker完成网络操作，调用方稍后重试。
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new OfficialPlatformException('RATE_LIMITED');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
