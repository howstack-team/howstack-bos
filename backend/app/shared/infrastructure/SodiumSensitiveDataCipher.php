<?php

declare(strict_types=1);

namespace app\shared\infrastructure;

use app\shared\contract\SensitiveDataCipherInterface;
use RuntimeException;
use SodiumException;

/** 使用 APP_KEY 派生的独立密钥加密敏感业务标识。 */
final readonly class SodiumSensitiveDataCipher implements SensitiveDataCipherInterface
{
    private string $encryptionKey;
    private string $fingerprintKey;

    public function __construct(string $applicationKey)
    {
        if (strlen($applicationKey) < 32) {
            throw new RuntimeException('APP_KEY 长度不足，无法安全保存敏感资料');
        }
        $this->encryptionKey = hash_hkdf('sha256', $applicationKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'hlyq-sensitive-encryption');
        $this->fingerprintKey = hash_hkdf('sha256', $applicationKey, 32, 'hlyq-sensitive-fingerprint');
    }

    /** {@inheritDoc} */
    public function encrypt(string $plaintext): string
    {
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->encryptionKey));
        } catch (SodiumException $exception) {
            throw new RuntimeException('敏感资料加密失败', 0, $exception);
        }
    }

    /** {@inheritDoc} */
    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('敏感资料密文格式无效');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);
        } catch (SodiumException $exception) {
            throw new RuntimeException('敏感资料解密失败', 0, $exception);
        }
        if (!is_string($plaintext)) {
            throw new RuntimeException('敏感资料密文校验失败');
        }

        return $plaintext;
    }

    /** {@inheritDoc} */
    public function fingerprint(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->fingerprintKey);
    }
}
