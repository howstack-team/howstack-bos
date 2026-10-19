<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

use app\shared\plugin\dto\provider\VncConnection;

/** 保留原供应连接与实例身份，VNC会话使用当前审核通过的同ID插件。 */
interface ProviderVncGatewayInterface
{
    /**
     * @param array<string,mixed> $source 原交付来源
     * @return bool 明确支持VNC
     */
    public function supported(array $source): bool;

    /**
     * @param array<string,mixed> $source 已授权实例的原交付来源
     * @return VncConnection 事务外换取VNC连接
     */
    public function open(array $source): VncConnection;
}
