<?php

declare(strict_types=1);

namespace app\shared\contract;

/** 查询可作为业务日志快照保存的 IP 地点。 */
interface IpLocationResolverInterface
{
    public function resolve(string $ip): string;
}
