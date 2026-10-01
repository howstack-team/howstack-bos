<?php

declare(strict_types=1);

namespace app\shared\contract;

/** 对需要可恢复保存的敏感字段提供加密与稳定指纹。 */
interface SensitiveDataCipherInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $payload): string;

    public function fingerprint(string $plaintext): string;
}
