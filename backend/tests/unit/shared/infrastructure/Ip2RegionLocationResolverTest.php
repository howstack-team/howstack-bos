<?php

declare(strict_types=1);

namespace tests\unit\shared\infrastructure;

use app\shared\infrastructure\Ip2RegionLocationResolver;
use Ip2Region;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Ip2RegionLocationResolverTest extends TestCase
{
    public function testSpecialAndInvalidIpAddressesUseStableLabels(): void
    {
        $resolver = new Ip2RegionLocationResolver(new Ip2Region());

        self::assertSame('本机', $resolver->resolve('127.0.0.1'));
        self::assertSame('本机', $resolver->resolve('::1'));
        self::assertSame('内网', $resolver->resolve('192.168.1.2'));
        self::assertSame('内网', $resolver->resolve('fd00::1'));
        self::assertSame('内网', $resolver->resolve('fe80::1'));
        self::assertSame('内网', $resolver->resolve('100.64.1.1'));
        self::assertSame('未知', $resolver->resolve('0.0.0.0'));
        self::assertSame('未知', $resolver->resolve('bad-ip'));
        self::assertSame('未知', $resolver->resolve('2400:3200::1'));
        self::assertSame('未知', $resolver->resolve('2001:db8::1'));
    }

    public function testPublicIpv4NormalizesLocationAndHandlesLookupFailure(): void
    {
        $lookup = new class () extends Ip2Region {
            public bool $fail = false;

            public function getIpInfo(string $ip): ?array
            {
                if ($this->fail) {
                    throw new RuntimeException('database unavailable');
                }

                return ['country' => '中国', 'province' => '广东省', 'city' => '0'];
            }
        };
        $resolver = new Ip2RegionLocationResolver($lookup);

        self::assertSame('中国 广东省', $resolver->resolve('114.114.114.114'));
        $lookup->fail = true;
        self::assertSame('未知', $resolver->resolve('114.114.114.114'));
    }
}
