<?php

declare(strict_types=1);

namespace app\modules\official_platform\domain;

/** 与官网共用无Bearer的授权码安装签名原始字节协议。 */
final class ClientRequestProof
{
    public static function canonical(string $software, string $installation, string $method, string $uri, string $body, string $timestamp, string $nonce): string
    {
        return implode("\n", ['HOWSTACK-CLIENT-V1', $software, $installation, strtoupper($method), $uri, hash('sha256', $body), $timestamp, $nonce]);
    }
}
