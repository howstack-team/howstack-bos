<?php

declare(strict_types=1);

namespace app\modules\product\application;

use app\modules\product\contract\{OrderSalesCatalogInterface, ProductTypeRegistryInterface, ProductPurchaseInputInterface, ProductPurchaseAvailabilityInterface};
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 匿名产品目录、选配和报价；绝不公开销售快照、提供者及交付映射。 */
final readonly class PublicProductPurchaseService
{
    private const array RESOURCE_FIELDS = ['id', 'name', 'description', 'country_code', 'test_ip', 'region_id', 'group_id', 'type_id',
        'cpu', 'memory_mib', 'architecture', 'min_cpu', 'min_memory_mib', 'min_disk_gib', 'default_username', 'default_port',
        'icon_url', 'cpu_model', 'base_clock_mhz', 'boost_clock_mhz', 'min_gib', 'max_gib', 'step_gib', 'max_count', 'capacity_gbps'];

    public function __construct(private OrderSalesCatalogInterface $catalog, private ProductTypeRegistryInterface $types,
        private ProductPurchaseInputInterface $inputs, private ProductPurchaseAvailabilityInterface $availability) {}

    /** @return list<array<string, mixed>> 当前启用的包年包月产品 */
    public function products(): array
    {
        $result = [];
        foreach ($this->catalog->choices() as $choice) {
            if ($choice['product_type'] !== 'cloud_server') continue;
            try { $sale = $this->catalog->published($choice['id'], $choice['version_id']); }
            catch (BusinessException) { continue; }
            if (($sale['snapshot']['schema_version'] ?? '') !== '2.0.0') continue;
            $result[] = $choice + ['purchase_url' => '/console/purchase/cloud-server?offer=' . $choice['id'],
                'description' => '包年包月，支持地域、规格、镜像与网络选配'];
        }
        return $result;
    }

    /** @param int $offerId 产品配置 @param int $versionId 当前销售版本 @param array<string, mixed> $selection 选配 @return array<string, mixed> 公开联动选项 */
    public function options(int $offerId, int $versionId, array $selection): array
    {
        $sale = $this->sale($offerId, $versionId);
        $result = $this->types->get($sale['product_type'])->resolve($sale['snapshot'], $this->inputs->clean($sale['product_type'], $selection));
        $options = [];
        foreach (['regions', 'zones', 'lines', 'flavor_groups', 'flavors', 'image_types', 'images', 'disk_types', 'protections'] as $kind) {
            $options[$kind] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(self::RESOURCE_FIELDS)), $result['options'][$kind]);
        }
        return ['schema_version' => '2.0.0', 'options' => $options,
            'configuration' => $this->inputs->clean($sale['product_type'], $result['configuration']),
            'invalid_fields' => $result['invalid_fields'], 'quantity_limit' => $result['quantity_limit'],
            'line_network' => $result['line_network'], 'durations' => $result['durations'],
            'storage_policy' => $result['storage_policy'], 'system_disk_included_gib' => $result['system_disk_included_gib']];
    }

    /** @param int $offerId 产品配置 @param int $versionId 当前版本 @param array<string, mixed> $selection 完整选择 @return array<string, mixed> 服务端计算的整数分报价 */
    public function quote(int $offerId, int $versionId, array $selection): array
    {
        $sale = $this->sale($offerId, $versionId);
        $price = $this->types->get($sale['product_type'])->price($sale['snapshot'], $this->inputs->clean($sale['product_type'], $selection));
        $this->availability->check($sale['product_type'], $price['configuration']);
        $price['unit_breakdown'] = array_map(static fn (array $part): array => array_intersect_key($part, array_flip([
            'key', 'label', 'quantity', 'unit_price_minor', 'amount_minor', 'pricing_mode', 'recurring', 'subtotal_minor', 'discount_minor',
        ])), $price['unit_breakdown']);
        return array_intersect_key($price, array_flip(['configuration', 'currency', 'quantity', 'unit_amount_minor', 'amount_minor', 'subtotal_minor', 'discount_minor', 'unit_breakdown']));
    }

    /** @param int $offerId 产品配置 @param int $versionId 销售版本 @return array<string, mixed> 内部销售资料 */
    private function sale(int $offerId, int $versionId): array
    {
        $sale = $this->catalog->published($offerId, $versionId);
        if ($sale['product_type'] !== 'cloud_server' || ($sale['snapshot']['schema_version'] ?? '') !== '2.0.0') {
            throw new BusinessException('该产品尚未开放会员购买', ApiErrorCode::NOT_FOUND, 404);
        }
        return $sale;
    }
}
