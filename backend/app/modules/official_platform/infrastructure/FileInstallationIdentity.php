<?php

declare(strict_types=1);

namespace app\modules\official_platform\infrastructure;

use app\modules\official_platform\contract\InstallationIdentityInterface;
use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\official_platform\domain\InstanceUuid;

/** 首次生成稳定安装身份，更新、容器重建与跨Worker继续复用私有持久卷。 */
final readonly class FileInstallationIdentity implements InstallationIdentityInterface
{
    /** @param string $privateDirectory Web根目录外的固定持久目录 */
    public function __construct(private string $privateDirectory)
    {
    }

    /** @inheritDoc */
    public function id(): string
    {
        return $this->identity()['installation_id'];
    }

    /** @inheritDoc */
    public function publicKey(): string
    {
        return $this->identity()['public_key'];
    }

    /** @inheritDoc */
    public function sign(string $bytes): string
    {
        return base64_encode(sodium_crypto_sign_detached($bytes, base64_decode($this->identity()['secret_key'], true)));
    }

    /** @return array{installation_id:string, public_key:string, secret_key:string} 私有身份，禁止公开和日志输出 */
    private function identity(): array
    {
        PrivateInstallationFiles::ensureDirectory($this->privateDirectory);
        $path = $this->privateDirectory . '/identity.json';
        $lockPath = $this->privateDirectory . '/identity.lock';
        if (is_link($path) || is_link($lockPath)) {
            throw new OfficialPlatformException('INVALID_IDENTITY');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
        chmod($lockPath, 0600);
        try {
            if (!is_file($path)) {
                $keys = sodium_crypto_sign_keypair();
                PrivateInstallationFiles::write($path, json_encode([
                    'installation_id' => InstanceUuid::create(),
                    'public_key' => base64_encode(sodium_crypto_sign_publickey($keys)),
                    'secret_key' => base64_encode(sodium_crypto_sign_secretkey($keys)),
                ], JSON_THROW_ON_ERROR));
            }
            if (filesize($path) > 4096) {
                throw new OfficialPlatformException('INVALID_IDENTITY');
            }
            try {
                $data = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new OfficialPlatformException('INVALID_IDENTITY', $error);
            }
            if (!is_array($data) || !is_string($data['secret_key'] ?? null) || !is_string($data['public_key'] ?? null)
                || !is_string($data['installation_id'] ?? null)) {
                throw new OfficialPlatformException('INVALID_IDENTITY');
            }
            $secret = base64_decode($data['secret_key'] ?? '', true);
            $public = base64_decode($data['public_key'] ?? '', true);
            if (!InstanceUuid::isValid($data['installation_id']) || !is_string($secret) || strlen($secret) !== 64
                || !is_string($public) || strlen($public) !== 32 || !hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($secret))) {
                throw new OfficialPlatformException('INVALID_IDENTITY');
            }
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
