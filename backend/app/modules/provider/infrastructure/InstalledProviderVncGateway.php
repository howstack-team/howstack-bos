<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure;

use app\modules\provider\contract\{ProviderAdapterRegistryInterface, ProviderVncAdapterInterface, ProviderVncGatewayInterface};
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\plugin\dto\provider\{InstanceIdentity, VncConnection};
use app\shared\plugin\exception\PluginException;

/** 只访问原供应账号和资源，使用当前审核通过的同一提供者VNC能力。 */
final readonly class InstalledProviderVncGateway implements ProviderVncGatewayInterface
{
    /**
     * @param ProviderAdapterRegistryInterface $adapters 已审核插件
     * @param SensitiveDataCipherInterface $cipher 原连接解密
     */
    public function __construct(private ProviderAdapterRegistryInterface $adapters, private SensitiveDataCipherInterface $cipher)
    {
    }

    /** @inheritDoc */
    public function supported(array $source): bool
    {
        $adapter = $this->adapter($source);
        return $adapter instanceof ProviderVncAdapterInterface && $adapter->supportsVnc();
    }

    /** @inheritDoc */
    public function open(array $source): VncConnection
    {
        $adapter = $this->adapter($source);
        if (!$adapter instanceof ProviderVncAdapterInterface || !$adapter->supportsVnc()) {
            throw new PluginException('原供应插件不支持VNC', 'vnc_unsupported');
        }
        $config = json_decode($this->cipher->decrypt($source['connection_encrypted']), true, flags: JSON_THROW_ON_ERROR);
        return $adapter->openVnc($config, new InstanceIdentity($source['external_id'], $source['request_key']));
    }

    /**
     * @param array<string,mixed> $source 原绑定
     * @return \app\modules\provider\contract\ProviderAdapterInterface 当前审核通过的同ID插件实例
     */
    private function adapter(array $source): \app\modules\provider\contract\ProviderAdapterInterface
    {
        $definition = $this->adapters->definition($source['adapter_id']);
        if (!$definition) {
            throw new PluginException('原供应插件未通过审核或不可用', 'adapter_changed');
        }
        return $this->adapters->get($source['adapter_id']);
    }
}
