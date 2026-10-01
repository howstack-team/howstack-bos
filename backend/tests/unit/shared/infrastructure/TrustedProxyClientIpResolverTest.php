<?php

declare(strict_types=1);

namespace tests\unit\shared\infrastructure;

use app\shared\infrastructure\TrustedProxyClientIpResolver;
use PHPUnit\Framework\TestCase;
use support\Request;

final class TrustedProxyClientIpResolverTest extends TestCase
{
    public function testDirectRequestIgnoresSpoofedForwardedHeader(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        self::assertSame('203.0.113.20', $resolver->resolve($this->request('203.0.113.20', '1.2.3.4')));
    }

    public function testTrustedProxyUsesNearestUntrustedHop(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8', '2001:db8::/32']);

        self::assertSame('198.51.100.10', $resolver->resolve($this->request('10.0.0.2', '1.2.3.4, 198.51.100.10')));
        self::assertSame('2400:3200::1', $resolver->resolve($this->request('2001:db8::2', '2400:3200::1')));
    }

    public function testInvalidForwardedHeaderFallsBackToConnectionAddress(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        self::assertSame('10.0.0.2', $resolver->resolve($this->request('10.0.0.2', 'not-an-ip')));
    }

    private function request(string $remote, string $forwarded): Request
    {
        return new class ($remote, $forwarded) extends Request {
            public function __construct(private string $remote, private string $forwarded)
            {
            }

            public function getRemoteIp(): string
            {
                return $this->remote;
            }

            public function header(?string $name = null, mixed $default = null): mixed
            {
                return $name === 'x-forwarded-for' ? $this->forwarded : $default;
            }
        };
    }
}
