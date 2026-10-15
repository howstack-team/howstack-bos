<?php

declare(strict_types=1);
namespace tests\unit\modules\admin\auth\infrastructure;
use app\modules\admin\auth\infrastructure\OtphpTotp;
use PHPUnit\Framework\TestCase;
/** RFC6238附录B的SHA1测试向量，取六位模式，并覆盖时钟偏差和已消费时间步。 */
final class OtphpTotpTest extends TestCase
{
    public function testRfc6238VectorsAndReplayBoundary(): void
    {
        $engine = new OtphpTotp();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037']] as [$time, $code]) {
            $step = intdiv($time, 30);
            self::assertSame($step, $engine->counter($secret, $code, -1, $time));
            self::assertNull($engine->counter($secret, $code, $step, $time));
        }
        self::assertSame(1, $engine->counter($secret, '287082', -1, 89));
        self::assertNull($engine->counter($secret, '287082', -1, 120));
        self::assertNull($engine->counter($secret, '１２３４５６', -1, 59));
        self::assertStringStartsWith('otpauth://totp/', $engine->uri($engine->generate(), '管理员'));
    }
}
