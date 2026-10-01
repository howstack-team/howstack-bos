<?php

declare(strict_types=1);

namespace app\shared\infrastructure;

use app\shared\contract\ClientIpResolverInterface;
use support\Request;

/** 仅在连接对端属于配置的受信任代理时读取 X-Forwarded-For。 */
final readonly class TrustedProxyClientIpResolver implements ClientIpResolverInterface
{
    /** @param list<string> $trustedProxies 代理 IP 或 CIDR 网段 */
    public function __construct(private array $trustedProxies = [])
    {
    }

    public function resolve(Request $request): string
    {
        $remote = $request->getRemoteIp();
        if (!$this->isTrusted($remote)) {
            return $remote;
        }

        $forwarded = $request->header('x-forwarded-for', '');
        if (!is_string($forwarded) || $forwarded === '') {
            return $remote;
        }

        // 从右向左剥离受信任的代理，不能采信客户端可自行填写的最左侧地址。
        $chain = array_map('trim', explode(',', $forwarded));
        $chain[] = $remote;
        for ($index = count($chain) - 1; $index >= 0; --$index) {
            if (filter_var($chain[$index], FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!$this->isTrusted($chain[$index])) {
                return $chain[$index];
            }
        }

        return $chain[0];
    }

    private function isTrusted(string $ip): bool
    {
        $address = inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($this->trustedProxies as $range) {
            [$network, $prefix] = array_pad(explode('/', trim($range), 2), 2, null);
            $packed = inet_pton($network);
            if ($packed === false || strlen($packed) !== strlen($address)) {
                continue;
            }
            $bits = $prefix === null ? strlen($address) * 8 : filter_var($prefix, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => strlen($address) * 8]]);
            if ($bits === false) {
                continue;
            }
            $whole = intdiv($bits, 8);
            $remainder = $bits % 8;
            if (substr($address, 0, $whole) !== substr($packed, 0, $whole)) {
                continue;
            }
            if ($remainder === 0 || ((ord($address[$whole]) ^ ord($packed[$whole])) & (0xff << (8 - $remainder))) === 0) {
                return true;
            }
        }

        return false;
    }
}
