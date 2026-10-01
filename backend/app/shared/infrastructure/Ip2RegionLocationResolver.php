<?php

declare(strict_types=1);

namespace app\shared\infrastructure;

use app\shared\contract\IpLocationResolverInterface;
use Ip2Region;
use Throwable;

/** 将 ip2region 查询结果规范化为日志地点文本。 */
final readonly class Ip2RegionLocationResolver implements IpLocationResolverInterface
{
    public function __construct(private Ip2Region $lookup, private ?string $ipv6DatabasePath = null)
    {
    }

    public function resolve(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '未知';
        }
        $packed = inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16
            && substr($packed, 0, 12) === hex2bin('00000000000000000000ffff')) {
            $ip = (string) inet_ntop(substr($packed, 12));
            $packed = inet_pton($ip);
        }
        if ((filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.')) || $ip === '::1') {
            return '本机';
        }
        if ($ip === '0.0.0.0' || $ip === '::') {
            return '未知';
        }
        $ipv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $number = $ipv4 ? ip2long($ip) : false;
        $linkLocalV4 = $number !== false && (($number & 0xffff0000) === ip2long('169.254.0.0'));
        $sharedV4 = $number !== false && (($number & 0xffc00000) === ip2long('100.64.0.0'));
        $linkLocalV6 = $packed !== false && strlen($packed) === 16
            && ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false || $linkLocalV4 || $sharedV4 || $linkLocalV6) {
            return '内网';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && ($this->ipv6DatabasePath === null || !is_file($this->ipv6DatabasePath))) {
            return '未知';
        }

        try {
            $info = $this->lookup->getIpInfo($ip);
            if (!is_array($info)) {
                return '未知';
            }
            $parts = array_values(array_filter(array_map(
                static fn (string $key): string => trim((string) ($info[$key] ?? '')),
                ['country', 'province', 'city'],
            ), static fn (string $value): bool => $value !== '' && $value !== '0'));

            return $parts === [] ? '未知' : mb_substr(implode(' ', $parts), 0, 128);
        } catch (Throwable) {
            return '未知';
        }
    }
}
