<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\exception\BusinessException;
use app\modules\compute\contract\ComputeInventoryRepositoryInterface;
use app\modules\compute\validation\ComputePurchaseValidator;
use app\modules\product\contract\ProductPurchaseHandlerInterface;
use app\shared\http\ApiErrorCode;

/** 云服务器负责自己的公开选项、购买校验、库存与会员购买能力。 */
final readonly class ComputePurchaseHandler implements ProductPurchaseHandlerInterface
{
    private const array RESOURCE_FIELDS = [
        'id', 'name', 'description', 'country_code', 'test_ip', 'region_id', 'group_id', 'type_id',
        'cpu', 'memory_mib', 'architecture', 'min_cpu', 'min_memory_mib', 'min_disk_gib',
        'default_username', 'default_port', 'icon_url', 'cpu_model', 'base_clock_mhz', 'boost_clock_mhz',
        'min_gib', 'max_gib', 'step_gib', 'max_count', 'capacity_gbps',
    ];

    public function __construct(
        private ComputeInventoryRepositoryInterface $inventory,
        private OrderComputeCapacityService $capacity,
    ) {
    }

    public function type(): string
    {
        return 'cloud_server';
    }

    public function clean(array $input): array
    {
        return ComputePurchaseValidator::clean($input);
    }

    public function supportsPublic(array $snapshot): bool
    {
        return ($snapshot['schema_version'] ?? null) === '2.0.0';
    }

    public function publicProduct(array $choice): array
    {
        return $choice + [
            'purchase_url' => '/console/purchase/cloud-server?offer=' . $choice['id'],
            'description' => '包年包月，支持地域、规格、镜像与网络选配',
        ];
    }

    public function publicOptions(array $resolved): array
    {
        $options = [];
        foreach (['regions', 'zones', 'lines', 'flavor_groups', 'flavors', 'image_types', 'images', 'disk_types', 'protections'] as $kind) {
            $options[$kind] = array_map(
                static fn (array $row): array => array_intersect_key($row, array_flip(self::RESOURCE_FIELDS)),
                $resolved['options'][$kind],
            );
        }
        return [
            'schema_version' => '2.0.0', 'options' => $options,
            'configuration' => $this->clean($resolved['configuration']),
            'invalid_fields' => $resolved['invalid_fields'], 'quantity_limit' => $resolved['quantity_limit'],
            'line_network' => $resolved['line_network'], 'durations' => $resolved['durations'],
            'storage_policy' => $resolved['storage_policy'],
            'system_disk_included_gib' => $resolved['system_disk_included_gib'],
        ];
    }

    public function check(array $configuration): void
    {
        foreach ($this->inventory->all() as $pool) {
            if ($pool['zone_id'] === $configuration['zone_id']
                && $pool['flavor_id'] === $configuration['flavor_id']
                && $pool['available'] !== null
                && $pool['available'] < ($configuration['quantity'] ?? 1)) {
                throw new BusinessException('所选规格库存不足，请调整配置或购买数量', ApiErrorCode::CONFLICT, 409);
            }
        }
    }

    public function occupy(array $configuration, array $unitIds): void
    {
        $this->capacity->occupy($configuration, $unitIds);
    }

    public function release(int $unitId): void
    {
        $this->capacity->release($unitId);
    }
}
