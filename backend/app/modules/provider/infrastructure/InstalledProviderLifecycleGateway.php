<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure;

use app\modules\provider\contract\{ProviderAdapterRegistryInterface, ProviderLifecycleAdapterInterface, ProviderLifecycleGatewayInterface};
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\plugin\dto\provider\{LifecycleRequest, LifecycleResult};
use app\shared\plugin\exception\PluginException;

/** 固定原插件、连接密文与资源身份；配置变更不改变存量资源的操作目标。 */
final readonly class InstalledProviderLifecycleGateway implements ProviderLifecycleGatewayInterface, \app\modules\provider\contract\ProviderInstanceOperationsGatewayInterface
{
    /**
     * @param ProviderAdapterRegistryInterface $adapters 原供应插件
     * @param SensitiveDataCipherInterface $cipher 连接解密
     */
    public function __construct(private ProviderAdapterRegistryInterface $adapters, private SensitiveDataCipherInterface $cipher)
    {
    }

    /** @inheritDoc */
    public function assertSupported(array $source, string $action = 'renew'): void
    {
        $adapter = $this->originalAdapter($source);
        if (in_array($action, ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'], true)) {
            if (!$adapter instanceof \app\modules\provider\contract\ProviderInstanceOperationsAdapterInterface || !in_array($action, $adapter->instanceOperationActions(), true)) {
                throw new PluginException('原供应插件不支持此运维动作', 'operation_unsupported');
            }
            return;
        }
        if (!$adapter instanceof ProviderLifecycleAdapterInterface || !$adapter->supportsLifecycle()) {
            throw new PluginException('原供应插件尚未接入续费和到期处理', 'lifecycle_unsupported');
        }
    }

    /**
     * @param array<string,mixed> $source 原供应来源
     * @return \app\modules\provider\contract\ProviderAdapterInterface 原摘要对应适配器
     */
    private function originalAdapter(array $source): \app\modules\provider\contract\ProviderAdapterInterface
    {
        $definition = $this->adapters->definition($source['adapter_id']);
        if (!$definition || $definition['manifest_hash'] !== $source['package_hash']) {
            throw new PluginException('原供应插件不可用或摘要已变化，需管理员核查', 'adapter_changed');
        }
        return $this->adapters->get($source['adapter_id']);
    }

    /** @inheritDoc */
    public function actions(array $source): array
    {
        $adapter = $this->originalAdapter($source);
        return $adapter instanceof \app\modules\provider\contract\ProviderInstanceOperationsAdapterInterface ? $adapter->instanceOperationActions() : [];
    }

    /** @inheritDoc */
    public function execute(array $task): LifecycleResult
    {
        $source = $task['source'];
        $this->assertSupported($source, $task['action']);
        $adapter = $this->adapters->get($source['adapter_id']);
        $config = json_decode($this->cipher->decrypt($source['connection_encrypted']), true, flags: JSON_THROW_ON_ERROR);
        if (in_array($task['action'], ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'], true)) {
            if (!$adapter instanceof \app\modules\provider\contract\ProviderInstanceOperationsAdapterInterface) {
                throw new PluginException('原供应插件不支持运维', 'operation_unsupported');
            }
            $password = null;
            if (!$task['query_only'] && in_array($task['action'], ['reinstall', 'reset_password'], true)) {
                if (!$task['password_encrypted'] || $task['credential_expires_at'] <= date('Y-m-d H:i:s')) {
                    throw new PluginException('运维密码已过期，请重新提交', 'credential_unavailable');
                }
                $password = $this->cipher->decrypt($task['password_encrypted']);
            }
            return $adapter->instanceOperation($config, new \app\shared\plugin\dto\provider\InstanceOperationRequest(
                $task['request_key'], $source['external_id'], $source['request_key'], $task['action'],
                $task['operation_context'] ?? [], $password, $task['provider_task_id'] ?? null,
            ), $task['query_only']);
        }
        return $adapter->lifecycle($config, new LifecycleRequest(
            $task['request_key'], $source['external_id'], $source['request_key'], $task['action'], $task['target_expires_at'],
            $task['months'], $task['expected_expires_at'],
        ), $task['query_only']);
    }
}
