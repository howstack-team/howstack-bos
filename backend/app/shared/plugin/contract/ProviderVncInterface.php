<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\ConnectionContext;
use app\shared\plugin\dto\provider\{InstanceIdentity, VncConnection};

/** 可选VNC能力，不引入自定义插件HTTP路由。 */
interface ProviderVncInterface
{
    /**
     * @param ConnectionContext $context 原供应连接
     * @param InstanceIdentity $instance 原实例身份，必须核对实例与主机名
     * @return VncConnection 本次直接连接所需的VNC凭据，不含系统登录密码
     */
    public function openVnc(ConnectionContext $context, InstanceIdentity $instance): VncConnection;
}
