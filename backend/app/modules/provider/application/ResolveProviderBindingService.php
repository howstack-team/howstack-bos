<?php

declare(strict_types=1);

namespace app\modules\provider\application;

use app\exception\BusinessException;
use app\modules\provider\contract\{ProviderAdapterRegistryInterface, ProviderBindingResolverInterface, ProviderRepositoryInterface};
use app\shared\http\ApiErrorCode;

/** 把内部稳定资源标识封存为指定连接的供应映射。 */
final readonly class ResolveProviderBindingService implements ProviderBindingResolverInterface
{
    public function __construct(private ProviderRepositoryInterface $connections, private ProviderAdapterRegistryInterface $adapters) {}

    public function resolve(int $connectionId, array $resources): array
    {
        $connection = $this->connections->find($connectionId);
        if (!$connection || $connection['status'] !== 1 || $connection['requires_retest']) {
            throw new BusinessException('请选择已测试并启用的资源供应商', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        if (!in_array($connection['adapter_id'], array_column($this->adapters->definitions(), 'id'), true)) {
            throw new BusinessException('对接插件已停止接单，不能发布新的交付绑定', ApiErrorCode::CONFLICT, 409);
        }
        $definition = $this->adapters->definition($connection['adapter_id']);
        if (!$definition || !in_array('cloud_server', $definition['capabilities']['product_types'], true)) throw new BusinessException('所选对接平台不支持云服务器');
        $mapping = $scoped = [];
        foreach ($this->connections->mappings($connectionId) as $row) {
            if (($row['scope_zone_id'] ?? 0) > 0) $scoped[$row['scope_zone_id']][$row['resource_kind']][$row['local_id']] = $row['external_id'];
            else $mapping[$row['resource_kind']][$row['local_id']] = $row['external_id'];
        }
        $catalog = [];
        foreach ($this->connections->catalogEntries($connectionId) as $entry) $catalog[$entry['kind']][$entry['external_id']] = $entry;
        $filtered = [];
        foreach ($mapping as $kind => $rows) $filtered[$kind] = array_intersect_key($rows, array_flip(array_column($resources[$kind] ?? [], 'id')));
        foreach ($resources['zones'] ?? [] as $zone) {
            $context = $this->zoneResources($resources, $zone);
            $resolved = [];
            foreach ($definition['catalogs'] as $descriptor) foreach ($context[$descriptor['resource_kind']] ?? [] as $row) {
                $kind = $descriptor['resource_kind'];
                $externalId = $scoped[$zone['id']][$kind][$row['id']] ?? $mapping[$kind][$row['id']] ?? null;
                if ($externalId === null) throw new BusinessException($zone['name'] . '：' . $row['name'] . '缺少供应商资源对应关系', ApiErrorCode::VALIDATION_FAILED, 422);
                if (!isset($catalog[$descriptor['kind']][$externalId])) throw new BusinessException($row['name'] . '的供应商资源已失效，请重新获取并设置对应关系');
                $resolved[$kind][$row['id']] = $externalId;
            }
            $this->adapters->get($connection['adapter_id'])->validateBinding($context, $resolved, $catalog);
            $filtered['_zones'][$zone['id']] = $resolved;
        }
        return ['connection_id' => $connectionId, 'mapping' => $filtered, 'capability_snapshot' => $connection['capabilities'] + [
            'adapter_id' => $definition['id'], 'plugin_id' => $definition['plugin_id'], 'adapter_version' => $definition['version'],
            'sdk_version' => $definition['sdk_version'], 'manifest_hash' => $definition['manifest_hash'], 'connection_revision' => $connection['revision'],
        ]];
    }

    /** 为统一插件 SDK 提供单可用区部署上下文。
     * @param array<string, list<array<string, mixed>>> $resources 可售资源
     * @param array<string, mixed> $zone 本次校验的可用区
     * @return array<string, list<array<string, mixed>>> 单部署位置的资源
     */
    private function zoneResources(array $resources, array $zone): array
    {
        $context = $resources;
        $context['zones'] = [$zone];
        $context['regions'] = array_values(array_filter($resources['regions'] ?? [], static fn (array $r): bool => $r['id'] === $zone['region_id']));
        foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) {
            $context[$kind] = array_values(array_filter($resources[$kind] ?? [], static fn (array $r): bool => in_array($zone['id'], $r['zone_ids'] ?? [$r['zone_id']], true)));
            foreach ($context[$kind] as &$row) $row['zone_id'] = $zone['id'];
            unset($row);
        }
        $context['flavor_groups'] = array_values(array_filter($resources['flavor_groups'] ?? [], static fn (array $r): bool => in_array($r['id'], array_column($context['flavors'], 'group_id'), true)));
        $context['protections'] = array_values(array_filter($resources['protections'] ?? [], static fn (array $r): bool => array_intersect($r['line_ids'] ?? [$r['line_id']], array_column($context['lines'], 'id')) !== []));
        return $context;
    }
}
