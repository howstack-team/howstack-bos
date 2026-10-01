<?php

declare(strict_types=1);

namespace tests\unit\shared\infrastructure;

use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;

/** 验证敏感资料加密可恢复且指纹稳定。 */
final class SodiumSensitiveDataCipherTest extends TestCase
{
    public function testEncryptsWithoutLeakingPlaintext(): void
    {
        $cipher = new SodiumSensitiveDataCipher(str_repeat('a', 64));
        $encrypted = $cipher->encrypt('510101199001011234');

        self::assertStringNotContainsString('510101199001011234', $encrypted);
        self::assertSame('510101199001011234', $cipher->decrypt($encrypted));
        self::assertSame($cipher->fingerprint('same'), $cipher->fingerprint('same'));
        self::assertNotSame($cipher->fingerprint('same'), $cipher->fingerprint('other'));
    }
}
