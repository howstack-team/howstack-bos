<?php

declare(strict_types=1);

namespace app\shared\contract;

use support\Request;

/** 从连接地址与受信任代理头中确定客户端 IP。 */
interface ClientIpResolverInterface
{
    public function resolve(Request $request): string;
}
