<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

use app\shared\plugin\dto\provider\{InstanceIdentity, VncConnection};

/** 宿主可选VNC SDK桥接。 */
interface ProviderVncAdapterInterface
{
    /**
     * @return bool 插件是否实现VNC能力
     */
    public function supportsVnc(): bool;

    /**
     * @param array<string,mixed> $config 原连接资料
     * @param InstanceIdentity $instance 固定资源身份
     * @return VncConnection 单次连接凭据
     */
    public function openVnc(array $config, InstanceIdentity $instance): VncConnection;
}
