<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

/** 按调用显式传递连接，不含用户、Request、容器或数据库对象。 */
final readonly class ConnectionContext
{
    /**
     * 创建当前连接上下文。
     * @param string        $pluginId      宿主确定的插件身份
     * @param Configuration $configuration 已授权解密的当前配置
     */
    public function __construct(public string $pluginId, public Configuration $configuration) {}
}
