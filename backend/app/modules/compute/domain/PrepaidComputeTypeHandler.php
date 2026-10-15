<?php

declare(strict_types=1);

namespace app\modules\compute\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** v2 专属购买配置；复用旧资源约束，历史版本不会自动升级。 */
final class PrepaidComputeTypeHandler
{
    /** @param array<string, mixed> $draft 草稿 @param array<string, mixed> $resources 当前资源 @return array<string, mixed> 不可变销售快照 */
    public function snapshot(array $draft, array $resources): array
    {
        $policy = (new PrepaidSalesConfiguration())->normalize($draft);
        unset($draft['schema_version']);
        $draft['cycles'] = ['month']; $draft['network_policy_id'] = 0;
        $draft['network'] = $this->legacyNetwork();
        $snapshot = (new ComputeTypeHandler())->snapshot($draft, $resources);
        // 新发布始终封存各可用区规则；旧草稿按原周期展开，不改写旧版本。
        $zoneDurations = $policy['zone_durations'] ?? array_map(
            static fn (array $zone): array => ['zone_id' => $zone['id'], 'durations' => $policy['durations']],
            $snapshot['resources']['zones'],
        );
        foreach ($snapshot['resources']['zones'] as $zone) {
            if (!in_array($zone['id'], array_column($zoneDurations, 'zone_id'), true)) $this->fail('zone_durations', $zone['name'] . '尚未配置付费周期');
        }
        $policy['zone_durations'] = $zoneDurations;
        unset($policy['durations']);
        foreach ($snapshot['resources']['lines'] as &$line) {
            if (!is_array($line['network_configuration'] ?? null)) $this->fail('line_id', $line['name'] . '尚未配置上下行带宽及IP');
            $line['network_configuration'] = (new LineNetworkConfiguration())->normalize($line['network_configuration']);
        }
        unset($line);
        $storage = $policy['storage_policy'];
        if ($storage['default_type_id'] && !in_array($storage['default_type_id'], array_column($snapshot['resources']['disk_types'], 'id'), true)) $this->fail('storage_policy', '默认存储类型不在可售范围');
        foreach ($snapshot['resources']['zones'] as $zone) {
            $disks = array_filter($snapshot['resources']['disk_types'], static fn (array $disk): bool => in_array($zone['id'], $disk['zone_ids'], true) && (!$storage['default_type_id'] || $disk['id'] === $storage['default_type_id']));
            if ($disks === []) $this->fail('storage_policy', '默认存储类型未覆盖所有可用区');
            $valid = array_filter($disks, static fn (array $disk): bool => $storage['default_size_gib'] >= $disk['min_gib'] && $storage['default_size_gib'] <= $disk['max_gib'] && ($storage['default_size_gib'] - $disk['min_gib']) % $disk['step_gib'] === 0);
            if ($valid === []) $this->fail('storage_policy', '默认系统盘容量超出存储范围或不符合步长');
        }
        foreach ($snapshot['resources']['images'] as $image) if (!in_array('password', $image['login_methods'], true)) $this->fail('resource_ids.images', '当前版本的可售镜像必须支持密码登录');
        foreach (['zone_ids' => 'zones', 'flavor_ids' => 'flavors', 'line_ids' => 'lines', 'image_ids' => 'images', 'disk_type_ids' => 'disk_types', 'protection_ids' => 'protections'] as $key => $kind) if (array_diff($policy['trial_scope'][$key], array_column($snapshot['resources'][$kind], 'id')) !== []) $this->fail('trial_scope', '试用范围必须属于可售资源');
        foreach ($draft['zone_provider_bindings'] ?? [] as $binding) {
            if (!in_array($binding['zone_id'], array_column($snapshot['resources']['zones'], 'id'), true)) {
                $this->fail('zone_provider_bindings', '供应商只能关联当前配置中的可用区');
            }
        }
        return array_replace($snapshot, $policy, ['zone_provider_bindings' => $draft['zone_provider_bindings'] ?? []]);
    }

    /** @param array<string, mixed> $snapshot 封存版本 @param array<string, mixed> $selection 选择 @return array<string, mixed> 联动选项 */
    public function resolve(array $snapshot, array $selection): array
    {
        $legacy = $this->legacySnapshot($snapshot);
        $result = (new ComputeTypeHandler())->resolve($legacy, $selection);
        $options = &$result['options'];
        foreach (['flavors' => ['flavor_group_id', 'group_id', 'flavor_id'], 'images' => ['image_type_id', 'type_id', 'image_id']] as $kind => [$parent, $relation, $field]) {
            if (isset($selection[$parent])) {
                $options[$kind] = array_values(array_filter($options[$kind], static fn (array $row): bool => $row[$relation] === $selection[$parent]));
                if (isset($selection[$field]) && !in_array($selection[$field], array_column($options[$kind], 'id'), true)) {
                    $result['invalid_fields'][$field] = '选择不属于当前分组'; unset($result['configuration'][$field]);
                }
            }
        }
        // 分组根据可用区以及已选规格的镜像约束派生，失效父级也会明确返回。
        foreach (['flavor_groups' => ['flavors', 'group_id', 'flavor_group_id'], 'image_types' => ['images', 'type_id', 'image_type_id']] as $kind => [$child, $relation, $field]) {
            $available = (new ComputeTypeHandler())->resolve($legacy, array_diff_key($selection, array_flip(['flavor_group_id', 'image_type_id'])))['options'][$child];
            $options[$kind] = array_values(array_filter($options[$kind], static fn (array $row): bool => in_array($row['id'], array_column($available, $relation), true)));
            if (isset($selection[$field]) && !in_array($selection[$field], array_column($options[$kind], 'id'), true)) $result['invalid_fields'][$field] = '分组不适用于当前配置';
        }
        $line = null;
        foreach ($options['lines'] as $row) if ($row['id'] === ($selection['line_id'] ?? null)) $line = $row;
        $result['line_network'] = $line['network_configuration'] ?? null;
        $zoneId = $result['configuration']['zone_id'] ?? null;
        $result['durations'] = [];
        if ($zoneId !== null) {
            if (array_key_exists('zone_durations', $snapshot)) {
                foreach ($snapshot['zone_durations'] as $binding) if ($binding['zone_id'] === $zoneId) $result['durations'] = $binding['durations'];
            } else {
                $result['durations'] = $snapshot['durations'];
            }
        }
        if (isset($selection['duration_months']) && !in_array($selection['duration_months'], array_column($result['durations'], 'months'), true)) {
            $result['invalid_fields']['duration_months'] = '请选择当前可用区支持的付费周期';
            unset($result['configuration']['duration_months']);
        }
        $result['storage_policy'] = $snapshot['storage_policy'];
        $result['schema_version'] = '2.0.0';
        $result['billing_mode'] = 'prepaid';
        return $result;
    }

    /** @param array<string, mixed> $snapshot 封存版本 @param array<string, mixed> $selection 输入 @return array<string, mixed> 单实例费用及总额 */
    public function price(array $snapshot, array $selection): array
    {
        $resolved = $this->resolve($snapshot, $selection);
        if ($resolved['invalid_fields']) $this->fail(array_key_first($resolved['invalid_fields']), reset($resolved['invalid_fields']));
        if (($selection['billing_mode'] ?? 'prepaid') !== 'prepaid' || ($selection['network']['billing_mode'] ?? 'fixed') !== 'fixed' || ($selection['login_method'] ?? 'password') !== 'password') $this->fail('billing_mode', '当前版本仅支持包年包月、固定带宽及密码登录');
        $duration = null;
        foreach ($resolved['durations'] as $period) if ($period['months'] === ($selection['duration_months'] ?? null)) $duration = $period;
        if (!$duration) $this->fail('duration_months', '请选择有效付费周期');
        if (count($selection['data_disks'] ?? []) > $snapshot['storage_policy']['max_data_disks']) $this->fail('data_disks', '数据盘总数量超出每实例限制');
        $legacySelection = $selection;
        $legacySelection['billing_cycle'] = 'month'; $legacySelection['network'] = ['bandwidth_mbps' => 0, 'ipv4_count' => 0];
        $price = (new ComputeTypeHandler())->price($this->legacySnapshot($snapshot), $legacySelection);
        $parts = array_values(array_filter($price['unit_breakdown'], static fn (array $part): bool => !in_array($part['key'], ['bandwidth', 'ip'], true)));
        $network = $resolved['line_network'];
        if (!$network) $this->fail('line_id', '请选择网络线路');
        $calculator = new LineNetworkConfiguration();
        foreach (['egress' => '上行带宽', 'ingress' => '下行带宽'] as $key => $label) {
            $value = $selection['network'][$key . '_mbps'] ?? null;
            if (!is_int($value)) $this->fail('network.' . $key . '_mbps', '请选择' . $label);
            $parts[] = ['key' => 'network.' . $key, 'label' => $label, 'quantity' => max(0, $value - $network[$key]['included']), 'pricing_mode' => $network[$key]['pricing_mode'], 'unit_price_minor' => $network[$key]['price_minor'], 'amount_minor' => $calculator->bandwidthAmount($network[$key], $value)];
        }
        $ips = $selection['network']['ipv4_count'] ?? null;
        if (!is_int($ips)) $this->fail('network.ipv4_count', '请选择IP数量');
        $calculator->validateIp($network['ip'], $ips);
        $parts[] = ['key' => 'network.ip', 'label' => 'IPv4', 'quantity' => max(0, $ips - $network['ip']['included']), 'unit_price_minor' => $network['ip']['price_minor'], 'amount_minor' => max(0, $ips - $network['ip']['included']) * $network['ip']['price_minor']];
        foreach ($parts as &$part) {
            $part['recurring'] = $part['key'] !== 'setup';
            $part['subtotal_minor'] = $part['amount_minor'] * ($part['recurring'] ? $duration['months'] : 1);
            // 在折扣乘法前约束金额，避免整数溢出转成浮点数。
            if ($part['subtotal_minor'] > 9000000000000) $this->fail('quantity', '原价金额超过支持范围');
            $part['amount_minor'] = !$part['recurring'] ? $part['amount_minor'] : ($duration['discount_kind'] === 'billed_months' ? $part['amount_minor'] * $duration['billed_months'] : intdiv($part['subtotal_minor'] * $duration['rate_bps'] + 5000, 10000));
            $part['discount_minor'] = $part['subtotal_minor'] - $part['amount_minor'];
            $part['facts'] = ['product_type' => 'cloud_server', 'zone_id' => $selection['zone_id'], 'line_id' => $selection['line_id'], 'flavor_id' => $selection['flavor_id'], 'image_id' => $selection['image_id'], 'protection_id' => $selection['protection_id'] ?? null, 'duration_months' => $duration['months'], 'component' => $part['key']];
        }
        unset($part);
        $price['unit_breakdown'] = $parts;
        $price['unit_amount_minor'] = array_sum(array_column($parts, 'amount_minor'));
        $price['amount_minor'] = $price['unit_amount_minor'] * $price['quantity'];
        if ($price['amount_minor'] > 9000000000000) $this->fail('quantity', '总金额超过支持范围');
        $price['subtotal_minor'] = array_sum(array_column($parts, 'subtotal_minor')) * $price['quantity'];
        if ($price['subtotal_minor'] > 9000000000000) $this->fail('quantity', '原价总金额超过支持范围');
        $price['discount_minor'] = $price['subtotal_minor'] - $price['amount_minor'];
        // 只取明确标准字段；嵌套结构同样收敛，敏感输入不会进入快照。
        $clean = $price['configuration']; unset($clean['billing_cycle']);
        $clean['system_disk'] = array_intersect_key($clean['system_disk'], array_flip(['type_id', 'size_gib']));
        $clean['data_disks'] = array_map(static fn (array $disk): array => array_intersect_key($disk, array_flip(['type_id', 'size_gib'])), $clean['data_disks']);
        $clean['network'] = ['billing_mode' => 'fixed', 'egress_mbps' => $selection['network']['egress_mbps'], 'ingress_mbps' => $selection['network']['ingress_mbps'], 'ipv4_count' => $ips];
        $clean['billing_mode'] = 'prepaid'; $clean['duration_months'] = $duration['months'];
        $price['configuration'] = $clean;
        return $price;
    }

    /** @param array<string, mixed> $snapshot 版本 @param array<string, mixed> $selection 配置 @return array<string, mixed> 标准交付参数 */
    public function provisioningConfiguration(array $snapshot, array $selection): array
    {
        $price = $this->price($snapshot, $selection);
        $legacy = $price['configuration']; $legacy['billing_cycle'] = 'month'; $legacy['network'] = ['bandwidth_mbps' => 0, 'ipv4_count' => 0];
        $configuration = (new ComputeTypeHandler())->provisioningConfiguration($this->legacySnapshot($snapshot), $legacy);
        unset($configuration['billing_cycle']);
        $configuration['network'] = $price['configuration']['network'];
        $configuration['duration'] = ['unit' => 'month', 'count' => $selection['duration_months']];
        $configuration['billing_mode'] = 'prepaid'; $configuration['contract_version'] = '2.0.0';
        $configuration['image']['default_username'] = $this->image($snapshot, $selection['image_id'])['default_username'] ?? '';
        $configuration['image']['default_port'] = $this->image($snapshot, $selection['image_id'])['default_port'] ?? 22;
        return $configuration;
    }

    /** @param array<string, mixed> $snapshot 已验证配置 @param array<string, mixed> $selection 完整配置 @return void 配额累计由交易层执行。 */
    public function validateTrial(array $snapshot, array $selection): void
    {
        $this->price($snapshot, $selection);
        if (!$snapshot['trial_policy']['enabled']) $this->fail('trial', '该销售方案未开放试用');
        if (($selection['quantity'] ?? 1) !== 1) $this->fail('quantity', '试用每次只能申请一台实例');
        $scope = $snapshot['trial_scope'];
        foreach (['zone_ids' => 'zone_id', 'flavor_ids' => 'flavor_id', 'line_ids' => 'line_id', 'image_ids' => 'image_id'] as $key => $field) if ($scope[$key] && !in_array($selection[$field], $scope[$key], true)) $this->fail($field, '所选配置未开放试用');
        foreach (['max_system_disk_gib' => $selection['system_disk']['size_gib'], 'max_data_disks' => count($selection['data_disks'] ?? []), 'max_egress_mbps' => $selection['network']['egress_mbps'], 'max_ingress_mbps' => $selection['network']['ingress_mbps'], 'max_ip_count' => $selection['network']['ipv4_count']] as $key => $value) if ($value > $scope[$key]) $this->fail('trial_scope', '所选配置超出试用限制');
        foreach (array_merge([$selection['system_disk']], $selection['data_disks'] ?? []) as $disk) if (($scope['disk_type_ids'] ?? []) && !in_array($disk['type_id'], $scope['disk_type_ids'], true)) $this->fail('system_disk.type_id', '该存储类型未开放试用');
        foreach ($selection['data_disks'] ?? [] as $disk) if ($disk['size_gib'] > ($scope['max_data_disk_gib'] ?? 65536)) $this->fail('data_disks', '单块数据盘超出试用容量');
        if (!empty($selection['protection_id']) && !in_array($selection['protection_id'], $scope['protection_ids'] ?? [], true)) $this->fail('protection_id', '所选防护未开放试用');
    }

    /** @param array<string, mixed> $snapshot 发布快照 @param int $id 镜像 @return array<string, mixed> 镜像默认登录信息 */
    private function image(array $snapshot, int $id): array
    {
        foreach ($snapshot['resources']['images'] as $image) if ($image['id'] === $id) return $image;
        return [];
    }

    /** @return array<string, mixed> 旧计价器所需的零网络配置 */
    private function legacyNetwork(): array
    {
        return ['bandwidth_min' => 0, 'bandwidth_max' => 0, 'bandwidth_step' => 1, 'ip_min' => 0, 'ip_max' => 0, 'bandwidth_billing_mode' => 'flat', 'bandwidth_tiers' => [], 'bandwidth_prices' => ['month' => 0], 'ip_prices' => ['month' => 0]];
    }

    /** @param array<string, mixed> $snapshot 发布快照 @return array<string, mixed> 旧资源校验器适配结构 */
    private function legacySnapshot(array $snapshot): array
    {
        unset($snapshot['schema_version']); $snapshot['network'] = $this->legacyNetwork();
        return $snapshot;
    }

    /** @param string $field 错误字段 @param string $message 校验原因 @return never */
    private function fail(string $field, string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => [$message]]]);
    }
}
