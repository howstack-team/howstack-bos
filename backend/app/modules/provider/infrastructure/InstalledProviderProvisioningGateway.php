<?php

declare(strict_types=1);
namespace app\modules\provider\infrastructure;

use app\exception\BusinessException;
use app\modules\provider\contract\{ProviderProvisioningGatewayInterface, ProviderRepositoryInterface, ProviderAdapterRegistryInterface, ProviderProvisioningPreviewInterface};
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\provider\{ProvisionRequest, ProvisionResult};
use app\shared\plugin\exception\PluginException;

/** 只使用付款时固定的连接配置及订单中的映射，重试不跟随后台修改。 */
final readonly class InstalledProviderProvisioningGateway implements ProviderProvisioningGatewayInterface
{
    /** @param ProviderRepositoryInterface $connections 供应连接 @param ProviderAdapterRegistryInterface $adapters 插件注册 @param ProviderProvisioningPreviewInterface $mapping 封存参数解释器 @param SensitiveDataCipherInterface $cipher 敏感字段加密器 @param \app\modules\plugin\contract\PluginInstallationRepositoryInterface $installations 安装记录锁 */
    public function __construct(private ProviderRepositoryInterface $connections, private ProviderAdapterRegistryInterface $adapters,
        private ProviderProvisioningPreviewInterface $mapping, private SensitiveDataCipherInterface $cipher, private \app\modules\plugin\contract\PluginInstallationRepositoryInterface $installations) {}
    /** @inheritDoc */
    public function capture(array $binding, array $configuration): array
    {
        $binding = $binding['zone_bindings'][$configuration['zone']['id']] ?? $binding;
        $connection = $this->connections->find((int) ($binding['connection_id'] ?? 0));
        $definition = $connection ? $this->adapters->definition($connection['adapter_id']) : null;
        if (!$connection || $connection['status'] !== 1 || $connection['requires_retest'] || !$definition || !$definition['accepting']
            || !($definition['capabilities']['production_ready'] ?? false)) {
            throw new BusinessException('资源供应商尚未支持或启用自动开通，请联系管理员', ApiErrorCode::CONFLICT, 409);
        }
        if (!in_array($configuration['product_type'] ?? '', $definition['capabilities']['product_types'] ?? [], true) || ($definition['capabilities']['type_version'] ?? null) !== ($configuration['contract_version'] ?? null)) {
            throw new BusinessException('资源供应商不支持当前产品交付契约，请更新插件和购买配置', ApiErrorCode::CONFLICT, 409);
        }
        if (($binding['capability_snapshot']['manifest_hash'] ?? '') !== $definition['manifest_hash']
            || ($binding['capability_snapshot']['connection_revision'] ?? null) !== $connection['revision']) {
            throw new BusinessException('资源供应商配置已变化，请重新保存购买配置', ApiErrorCode::CONFLICT, 409);
        }
        // 与插件停止接单及连接编辑共用行锁，不能将旧修订映射配上新账户凭据。
        $this->installations->lockAccepting($definition['id']);
        $config = $this->adapters->get($definition['id'])->normalizeConfiguration($this->connections->credentialsAtRevision($connection['id'], $connection['revision']));
        return ['adapter_id' => $definition['id'], 'package_hash' => $definition['manifest_hash'], 'connection_id' => $connection['id'],
            'connection_encrypted' => $this->cipher->encrypt(json_encode($config, JSON_THROW_ON_ERROR))];
    }
    /** @inheritDoc */
    public function execute(array $task, #[\SensitiveParameter] string $password): ProvisionResult
    {
        $definition = $this->adapters->definition($task['adapter_id']);
        if (!$definition || !($definition['capabilities']['production_ready'] ?? false)) throw new PluginException('原供应插件不可用', 'provider_unavailable');
        if ($definition['manifest_hash'] !== $task['package_hash']) throw new PluginException('原供应插件已变化，需人工核查后处理', 'adapter_changed');
        $config = json_decode($this->cipher->decrypt($task['connection_encrypted']), true, flags: JSON_THROW_ON_ERROR);
        $mapped = $this->mapping->build($task['context']['delivery_binding'], $task['context']['provisioning']);
        $request = new ProvisionRequest($task['request_key'], $mapped['configuration'], $mapped['provider_parameters'], $password, $task['external_id']);
        return $this->adapters->get($task['adapter_id'])->provision($config, $request, $task['operation'] === 'query');
    }
}
