<?php

declare(strict_types=1);

namespace app\modules\install\infrastructure;

use app\modules\install\contract\InstallationAuthorizationRepositoryInterface;
use app\modules\install\exception\InstallationException;

/** 不依赖未安装的数据库；部署凭据仅保存在Web根目录之外的私有目录。 */
final readonly class FileInstallationAuthorizationRepository implements InstallationAuthorizationRepositoryInterface
{
    public function __construct(private ?string $directory = null)
    {
    }

    public function issue(string $keyHash, int $expiresAt): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new InstallationException('无法创建安装授权目录', 500);
        }
        $temporary = tempnam($directory, '.install-key-');
        if ($temporary === false) throw new InstallationException('无法创建安装授权文件', 500);
        try {
            chmod($temporary, 0600);
            $content = json_encode(['hash' => $keyHash, 'expires_at' => $expiresAt], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $path)) {
                throw new InstallationException('无法保存安装授权文件', 500);
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    public function valid(string $proof): bool
    {
        $contents = is_file($this->path()) ? file_get_contents($this->path()) : false;
        $data = $contents === false ? null : json_decode($contents, true);
        return is_array($data) && (int) ($data['expires_at'] ?? 0) > time()
            && is_string($data['hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $proof)
            && hash_equals($data['hash'], $proof);
    }

    public function revoke(): void
    {
        if (is_file($this->path()) && !unlink($this->path())) {
            throw new InstallationException('安装授权文件清理失败', 500);
        }
    }

    private function path(): string
    {
        return ($this->directory ?? base_path('var/install')) . '/authorization.json';
    }
}
