<?php

declare(strict_types=1);

namespace app\modules\compute\domain;

use app\exception\BusinessException;
use app\modules\product\contract\ProductTypeHandlerInterface;
use app\shared\http\ApiErrorCode;

/** 云服务器联动、组合约束和整数配置计价，不访问数据库或供应商。 */
final class ComputeTypeHandler implements ProductTypeHandlerInterface
{
    public function type(): string { return 'cloud_server'; }
    public function version(): string { return '1.3.0'; }
    public function supportsVersion(string $version): bool { return in_array($version, ['1.0.0', '1.1.0', '1.2.0', '1.3.0', '2.0.0'], true); }

    public function snapshot(array $draft, array $resources): array
    {
        if (($draft['schema_version'] ?? null) === '2.0.0') return (new PrepaidComputeTypeHandler())->snapshot($draft, $resources);
        $selected = [];
        foreach (ComputeResources::KINDS as $kind) {
            $ids = $draft['resource_ids'][$kind] ?? [];
            $selected[$kind] = array_values(array_filter($resources[$kind] ?? [], static fn (array $row): bool => in_array($row['id'], $ids, true) && $row['status'] === 1));
            if (count($selected[$kind]) !== count($ids)) $this->error('resource_ids.' . $kind, '存在已停用或不存在的资源');
        }
        foreach (['regions', 'zones', 'lines', 'flavors', 'images', 'disk_types'] as $kind) {
            if ($selected[$kind] === []) $this->error('resource_ids.' . $kind, '请至少选择一项可售资源');
        }
        // 分组与镜像类型由子资源关系收集，不要求运营重复勾选父级。
        foreach (['flavor_groups' => ['flavors', 'group_id'], 'image_types' => ['images', 'type_id']] as $kind => [$child, $field]) {
            $ids = array_column($selected[$child], $field);
            $selected[$kind] = array_values(array_filter($resources[$kind] ?? [], static fn (array $row): bool => in_array($row['id'], $ids, true) && $row['status'] === 1));
            if (count(array_unique($ids)) !== count($selected[$kind])) $this->error('resource_ids.' . $child, '关联分组不存在或已停用');
        }
        foreach (['zones' => ['region_id', 'regions'], 'lines' => ['zone_id', 'zones'], 'flavors' => ['zone_id', 'zones'],
            'images' => ['zone_id', 'zones'], 'disk_types' => ['zone_id', 'zones'], 'protections' => ['line_id', 'lines']] as $kind => [$field, $parent]) {
            foreach ($selected[$kind] as $row) {
                $ids = $row[$field === 'zone_id' ? 'zone_ids' : 'line_ids'] ?? [$row[$field]];
                if (array_intersect($ids, array_column($selected[$parent], 'id')) === []) $this->error('resource_ids.' . $kind, '可售资源缺少关联的部署位置');
            }
        }
        // 只封存本销售方案开放的关联，供应绑定按每个可用区分别解析。
        foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) foreach ($selected[$kind] as &$row) {
            $row['zone_ids'] = array_values(array_intersect($row['zone_ids'] ?? [$row['zone_id']], array_column($selected['zones'], 'id')));
            $row['zone_id'] = $row['zone_ids'][0];
        }
        unset($row);
        foreach ($selected['protections'] as &$row) {
            $row['line_ids'] = array_values(array_intersect($row['line_ids'] ?? [$row['line_id']], array_column($selected['lines'], 'id')));
            $row['line_id'] = $row['line_ids'][0];
        }
        unset($row);
        (new BandwidthPricing())->validate($draft['network'], $draft['cycles']);
        foreach (['flavors', 'disk_types', 'protections'] as $kind) {
            foreach ($selected[$kind] as &$row) {
                $row['prices'] = $draft['price_overrides'][$kind][$row['id']] ?? $row['prices'];
                foreach ($draft['cycles'] as $cycle) {
                    if (!isset($row['prices'][$cycle]) || !is_int($row['prices'][$cycle]) || $row['prices'][$cycle] < 0) {
                        $this->error('price_overrides.' . $kind . '.' . $row['id'] . '.' . $cycle, $row['name'] . '缺少对应周期价格');
                    }
                }
            }
            unset($row);
        }
        if (!empty($draft['network_policy_id'])) {
            $policy = $this->find($resources['network_policies'] ?? [], $draft['network_policy_id']);
            if (!$policy || $policy['status'] !== 1) $this->error('network_policy_id', '网络策略不存在或已停用');
            $network = $draft['network'];
            if ($network['bandwidth_min'] < $policy['bandwidth_min'] || $network['bandwidth_max'] > $policy['bandwidth_max']
                || ($network['bandwidth_min'] - $policy['bandwidth_min']) % $policy['bandwidth_step'] !== 0
                || $network['bandwidth_step'] % $policy['bandwidth_step'] !== 0
                || $network['ip_min'] < $policy['ip_min'] || $network['ip_max'] > $policy['ip_max']) {
                $this->error('network', '销售网络范围超出所选策略或不符合带宽步长');
            }
            $selected['network_policies'] = [$policy];
        }
        $snapshot = ['resources' => $selected, 'cycles' => $draft['cycles'], 'network' => $draft['network'],
            'setup_minor' => $draft['setup_minor'], 'quantity_limit' => $draft['quantity_limit'],
            'system_disk_included_gib' => $this->includedSystemDiskCapacity($draft),
            'trial_policy' => $draft['trial_policy'], 'refund_policy' => $draft['refund_policy'], 'renewal_policy' => $draft['renewal_policy']];
        foreach ($selected['zones'] as $zone) {
            $flavors = $this->filter($selected['flavors'], 'zone_id', $zone['id']);
            $images = $this->filter($selected['images'], 'zone_id', $zone['id']);
            $disks = $this->filter($selected['disk_types'], 'zone_id', $zone['id']);
            $lines = $this->filter($selected['lines'], 'zone_id', $zone['id']);
            foreach ($flavors as $flavor) {
                $deliverable = false;
                foreach ($images as $image) foreach ($disks as $disk) {
                    $minimum = max($disk['min_gib'], $image['min_disk_gib']);
                    $minimum += ($disk['step_gib'] - (($minimum - $disk['min_gib']) % $disk['step_gib'])) % $disk['step_gib'];
                    if ($this->imageCompatible($image, $flavor) && $minimum <= $disk['max_gib']) $deliverable = true;
                }
                if (!$deliverable || $lines === []) $this->error('resource_ids.flavors', $flavor['name'] . '没有兼容镜像、系统盘或线路');
            }
            if ($flavors === [] || $lines === []) $this->error('resource_ids.zones', $zone['name'] . '没有完整可售配置');
        }
        return $snapshot;
    }

    public function resolve(array $snapshot, array $selection): array
    {
        if (($snapshot['schema_version'] ?? null) === '2.0.0') return (new PrepaidComputeTypeHandler())->resolve($snapshot, $selection);
        $r = $snapshot['resources'];
        $invalid = [];
        $filtered = ['regions' => $r['regions'], 'flavor_groups' => $r['flavor_groups'], 'image_types' => $r['image_types']];
        $chain = ['region_id' => 'regions', 'zone_id' => 'zones', 'line_id' => 'lines', 'flavor_id' => 'flavors', 'image_id' => 'images'];
        foreach ($chain as $field => $kind) {
            if ($kind === 'zones') $filtered[$kind] = $this->filter($r[$kind], 'region_id', $selection['region_id'] ?? null);
            elseif (in_array($kind, ['lines', 'flavors', 'images'], true)) $filtered[$kind] = $this->filter($r[$kind], 'zone_id', $selection['zone_id'] ?? null);
            if ($kind === 'images' && isset($selection['flavor_id'])) {
                $flavor = $this->find($filtered['flavors'], $selection['flavor_id']);
                if ($flavor) $filtered[$kind] = array_values(array_filter($filtered[$kind], fn (array $image): bool => $this->imageCompatible($image, $flavor)));
            }
            if (isset($selection[$field]) && $this->find($filtered[$kind], $selection[$field]) === null) {
                $invalid[$field] = '选择不属于当前可售组合';
                unset($selection[$field]);
            }
        }
        $filtered['disk_types'] = $this->filter($r['disk_types'], 'zone_id', $selection['zone_id'] ?? null);
        $filtered['protections'] = $this->filter($r['protections'], 'line_id', $selection['line_id'] ?? null);
        $filtered['network_policies'] = $r['network_policies'] ?? [];
        foreach (['system_disk' => $selection['system_disk'] ?? null] as $field => $disk) {
            if ($disk && $this->find($filtered['disk_types'], $disk['type_id'] ?? 0) === null) { $invalid[$field] = '磁盘类型不适用于当前可用区'; unset($selection[$field]); }
        }
        foreach ($selection['data_disks'] ?? [] as $index => $disk) {
            if ($this->find($filtered['disk_types'], $disk['type_id'] ?? 0) === null) $invalid['data_disks.' . $index] = '磁盘类型不适用于当前可用区';
        }
        if (!empty($selection['protection_id']) && $this->find($filtered['protections'], $selection['protection_id']) === null) {
            $invalid['protection_id'] = '防护不适用于当前线路'; unset($selection['protection_id']);
        }
        return ['options' => $filtered, 'configuration' => $selection, 'invalid_fields' => $invalid,
            'system_disk_included_gib' => $this->includedSystemDiskCapacity($snapshot),
            'network' => $snapshot['network'], 'cycles' => $snapshot['cycles'], 'quantity_limit' => $snapshot['quantity_limit']];
    }

    public function price(array $snapshot, array $selection): array
    {
        if (($snapshot['schema_version'] ?? null) === '2.0.0') return (new PrepaidComputeTypeHandler())->price($snapshot, $selection);
        $resolved = $this->resolve($snapshot, $selection);
        if ($resolved['invalid_fields'] !== []) $this->error(array_key_first($resolved['invalid_fields']), reset($resolved['invalid_fields']));
        $cycle = $selection['billing_cycle'] ?? '';
        if (!in_array($cycle, $snapshot['cycles'], true)) $this->error('billing_cycle', '请选择支持的计费周期');
        $quantity = $selection['quantity'] ?? 1;
        if (!is_int($quantity) || $quantity < 1 || $quantity > $snapshot['quantity_limit']) $this->error('quantity', '购买数量超出限制');
        foreach (['region_id' => 'regions', 'zone_id' => 'zones', 'line_id' => 'lines', 'flavor_id' => 'flavors', 'image_id' => 'images'] as $field => $kind) {
            if (!$this->find($resolved['options'][$kind], $selection[$field] ?? 0)) $this->error($field, '请选择有效配置');
        }
        $flavor = $this->find($resolved['options']['flavors'], $selection['flavor_id']);
        $image = $this->find($resolved['options']['images'], $selection['image_id']);
        $parts = [];
        $this->part($parts, 'compute', '计算规格', 1, $flavor['prices'][$cycle]);
        $this->disk($parts, 'system_disk', $selection['system_disk'] ?? [], $resolved['options']['disk_types'], $cycle, $image['min_disk_gib'], $resolved['system_disk_included_gib']);
        $counts = [];
        foreach ($selection['data_disks'] ?? [] as $index => $disk) {
            $type = $this->find($resolved['options']['disk_types'], $disk['type_id'] ?? 0);
            if (!$type) $this->error('data_disks.' . $index . '.type_id', '请选择有效磁盘类型');
            $counts[$type['id']] = ($counts[$type['id']] ?? 0) + 1;
            if ($counts[$type['id']] > $type['max_count']) $this->error('data_disks', '数据盘数量超出类型限制');
            $this->disk($parts, 'data_disks.' . $index, $disk, $resolved['options']['disk_types'], $cycle, 0);
        }
        $network = $snapshot['network'];
        foreach (['bandwidth_mbps' => ['bandwidth_min', 'bandwidth_max', 'bandwidth_step'], 'ipv4_count' => ['ip_min', 'ip_max', null]] as $field => [$min, $max, $step]) {
            $value = $selection['network'][$field] ?? null;
            if (!is_int($value) || $value < $network[$min] || $value > $network[$max]
                || ($step !== null && ($value - $network[$min]) % $network[$step] !== 0)) $this->error('network.' . $field, '网络配置超出范围或不符合步长');
        }
        array_push($parts, ...(new BandwidthPricing())->calculate($network, $selection['network']['bandwidth_mbps'], $cycle));
        $this->part($parts, 'ip', 'IPv4', $selection['network']['ipv4_count'], $network['ip_prices'][$cycle]);
        if (!empty($selection['protection_id'])) {
            $protection = $this->find($resolved['options']['protections'], $selection['protection_id']);
            $this->part($parts, 'protection', '防护', 1, $protection['prices'][$cycle]);
        }
        $method = $selection['login_method'] ?? 'password';
        if (!in_array($method, $image['login_methods'], true)) $this->error('login_method', '镜像不支持该登录方式');
        $this->part($parts, 'setup', '一次性费用', 1, $snapshot['setup_minor']);
        $unit = array_sum(array_column($parts, 'amount_minor'));
        if ($unit * $quantity > 9000000000000) $this->error('quantity', '总金额超过支持范围');
        $clean = array_intersect_key($selection, array_flip(['region_id', 'zone_id', 'line_id', 'flavor_id', 'image_id', 'system_disk', 'data_disks', 'network', 'protection_id', 'billing_cycle', 'quantity', 'login_method']));
        return ['configuration' => $clean + ['quantity' => $quantity, 'data_disks' => [], 'login_method' => $method],
            'unit_breakdown' => $parts, 'unit_amount_minor' => $unit, 'amount_minor' => $unit * $quantity,
            'currency' => 'CNY', 'quantity' => $quantity, 'preview' => true];
    }

    /** 标准参数完整表达规格、磁盘及网络，供应插件接收后转换为外部请求。
     * @param array<string, mixed> $snapshot 销售快照
     * @param array<string, mixed> $selection 购买选择
     * @return array<string, mixed> 白名单交付配置
     */
    public function provisioningConfiguration(array $snapshot, array $selection): array
    {
        if (($snapshot['schema_version'] ?? null) === '2.0.0') return (new PrepaidComputeTypeHandler())->provisioningConfiguration($snapshot, $selection);
        $configuration = $this->price($snapshot, $selection)['configuration'];
        $resources = $snapshot['resources'];
        $resource = fn (string $kind, int $id): array => array_intersect_key($this->find($resources[$kind], $id) ?? [], array_flip(['id', 'code', 'name', 'cpu', 'memory_mib', 'architecture', 'group_id', 'type_id', 'min_cpu', 'min_memory_mib', 'min_disk_gib', 'cpu_model', 'base_clock_mhz', 'boost_clock_mhz', 'read_mbps', 'write_mbps', 'read_iops', 'write_iops']));
        $flavor = $resource('flavors', $configuration['flavor_id']);
        $disk = fn (array $disk): array => ['type' => $resource('disk_types', $disk['type_id']), 'size_gib' => $disk['size_gib']];
        return ['product_type' => $this->type(), 'region' => $resource('regions', $configuration['region_id']), 'zone' => $resource('zones', $configuration['zone_id']),
            'line' => $resource('lines', $configuration['line_id']), 'flavor_group' => $resource('flavor_groups', $flavor['group_id']), 'flavor' => $flavor,
            'image' => $resource('images', $configuration['image_id']), 'system_disk' => $disk($configuration['system_disk']), 'data_disks' => array_map($disk, $configuration['data_disks']),
            'network' => $configuration['network'], 'protection' => !empty($configuration['protection_id']) ? $resource('protections', $configuration['protection_id']) : null,
            'billing_cycle' => $configuration['billing_cycle'], 'quantity' => $configuration['quantity'], 'login_method' => $configuration['login_method']];
    }

    /** @param list<array<string, mixed>> $rows 资源列表 @param mixed $id 标识 @return array<string, mixed>|null 匹配资源 */
    private function find(array $rows, mixed $id): ?array
    {
        foreach ($rows as $row) if ($row['id'] === $id) return $row;
        return null;
    }

    /** @param list<array<string, mixed>> $rows 列表 @param string $key 关系字段 @param mixed $value 选择值 @return list<array<string, mixed>> 可选资源 */
    private function filter(array $rows, string $key, mixed $value): array
    {
        return $value === null ? [] : array_values(array_filter($rows, static function (array $row) use ($key, $value): bool {
            $plural = match ($key) { 'zone_id' => 'zone_ids', 'line_id' => 'line_ids', default => null };
            return $plural !== null ? in_array($value, $row[$plural] ?? [$row[$key]], true) : $row[$key] === $value;
        }));
    }

    /** @param array<string, mixed> $image 镜像 @param array<string, mixed> $flavor 规格 @return bool 满足架构及最低计算配置。 */
    private function imageCompatible(array $image, array $flavor): bool
    {
        return $image['architecture'] === $flavor['architecture'] && $flavor['cpu'] >= ($image['min_cpu'] ?? 1) && $flavor['memory_mib'] >= ($image['min_memory_mib'] ?? 128);
    }

    /** @param list<array<string, mixed>> $parts 明细 @param string $key 项目标识 @param string $label 文案 @param int $quantity 数量 @param int $price 单价分 @return void */
    private function part(array &$parts, string $key, string $label, int $quantity, int $price): void
    {
        $parts[] = ['key' => $key, 'label' => $label, 'quantity' => $quantity, 'unit_price_minor' => $price, 'amount_minor' => $quantity * $price];
    }

    /** @param list<array<string, mixed>> $parts 明细 @param string $field 字段 @param array<string, mixed> $disk 完整交付配置 @param list<array<string, mixed>> $types 类型 @param string $cycle 周期 @param int $imageMin 镜像最低容量 @param int $includedGib 每台系统盘赠送容量 @return void */
    private function disk(array &$parts, string $field, array $disk, array $types, string $cycle, int $imageMin, int $includedGib = 0): void
    {
        $type = $this->find($types, $disk['type_id'] ?? 0);
        $size = $disk['size_gib'] ?? null;
        if (!$type || !is_int($size) || $size < max($type['min_gib'], $imageMin) || $size > $type['max_gib']
            || ($size - $type['min_gib']) % $type['step_gib'] !== 0) $this->error($field, '磁盘类型、容量或步长不符合要求');
        $label = $field === 'system_disk' ? '系统盘' : '数据盘';
        if ($includedGib > 0) $label .= '（赠送 ' . $includedGib . ' GiB）';
        // 赠送仅抵扣销售计费容量，镜像校验和供应交付仍使用完整容量。
        $this->part($parts, $field, $label, max(0, $size - $includedGib), $type['prices'][$cycle]);
    }

    /** @param array<string, mixed> $data 草稿或历史快照 @return int 每台赠送容量；旧版本默认为零。 */
    private function includedSystemDiskCapacity(array $data): int
    {
        $capacity = $data['system_disk_included_gib'] ?? 0;
        if (!is_int($capacity) || $capacity < 0 || $capacity > 65536) $this->error('system_disk_included_gib', '系统盘赠送容量必须为 0 至 65536 GiB 的整数');
        return $capacity;
    }

    /** @param string $field 字段路径 @param string $message 可展示原因 @return never 校验失败 */
    private function error(string $field, string $message): never
    {
        throw new BusinessException('配置校验失败：' . $message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => [$message]]]);
    }
}
