<?php

declare(strict_types=1);

namespace app\modules\provider\domain;

use app\modules\provider\contract\ProviderProvisioningPreviewInterface;

/** 供应商只需要映射其声明的目录；CPU、内存、磁盘容量及带宽作为数值传递。 */
final class ProviderProvisioningPreview implements ProviderProvisioningPreviewInterface
{
    /** @inheritDoc */
    public function build(array $binding, array $configuration): array
    {
        if (!empty($binding['zone_bindings'])) {
            $binding = $binding['zone_bindings'][$configuration['zone']['id']]
                ?? throw new \app\exception\BusinessException('该可用区未封存资源供应商绑定');
        }
        $mapping = $binding['mapping']['_zones'][$configuration['zone']['id']] ?? $binding['mapping'];
        foreach (['region' => 'regions', 'zone' => 'zones', 'line' => 'lines', 'flavor_group' => 'flavor_groups', 'flavor' => 'flavors', 'image' => 'images', 'protection' => 'protections'] as $field => $kind) {
            if ($configuration[$field] !== null) $configuration[$field]['external_id'] = $mapping[$kind][$configuration[$field]['id']] ?? null;
        }
        $configuration['system_disk']['type']['external_id'] = $mapping['disk_types'][$configuration['system_disk']['type']['id']] ?? null;
        foreach ($configuration['data_disks'] as &$disk) $disk['type']['external_id'] = $mapping['disk_types'][$disk['type']['id']] ?? null;
        unset($disk);
        $parameters = (new ProviderParameterMapping())->build($binding['parameter_mapping'] ?? [], $configuration);
        return ['provider_parameters' => $parameters, 'connection_id' => $binding['connection_id'], 'adapter_id' => $binding['capability_snapshot']['adapter_id'], 'configuration' => $configuration,
            'production_ready' => $binding['capability_snapshot']['production_ready'] ?? false, 'preview' => true];
    }
}
