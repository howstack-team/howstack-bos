<?php

declare(strict_types=1);

namespace app\modules\compute\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 云服务器 v2 月周期、默认存储与试用条件；不包含实时库存或凭据。 */
final class PrepaidSalesConfiguration
{
    /** @param array<string, mixed> $raw 草稿 @return array<string, mixed> 白名单配置 */
    public function normalize(array $raw): array
    {
        if (($raw['billing_mode'] ?? 'prepaid') !== 'prepaid') $this->fail('billing_mode', '当前版本仅支持包年包月');
        // 缺省才兼容旧全局周期；显式可用区配置不能回退到全局规则。
        $periodPolicy = [];
        if (array_key_exists('zone_durations', $raw)) {
            if (!is_array($raw['zone_durations']) || !array_is_list($raw['zone_durations']) || count($raw['zone_durations']) > 500) $this->fail('zone_durations', '可用区付费周期格式无效');
            $bindings = []; $seen = [];
            foreach ($raw['zone_durations'] as $index => $binding) {
                $field = 'zone_durations.' . $index;
                if (!is_array($binding)) $this->fail($field, '可用区付费周期格式无效');
                $zoneId = $this->integer($binding['zone_id'] ?? null, 1, PHP_INT_MAX, $field . '.zone_id');
                if (isset($seen[$zoneId])) $this->fail($field . '.zone_id', '可用区付费周期不能重复配置');
                if (!in_array($zoneId, $raw['resource_ids']['zones'] ?? [], true)) $this->fail($field . '.zone_id', '付费周期只能关联参与购买的可用区');
                $seen[$zoneId] = true;
                $bindings[] = ['zone_id' => $zoneId, 'durations' => $this->normalizeDurations($binding['durations'] ?? null, $field . '.durations')];
            }
            $periodPolicy['zone_durations'] = $bindings;
        } else {
            $periodPolicy['durations'] = $this->normalizeDurations($raw['durations'] ?? [['months' => 1, 'label' => '月付', 'discount_kind' => 'rate', 'rate_bps' => 10000]], 'durations');
        }
        $storage = $raw['storage_policy'] ?? [];
        if (!is_array($storage)) $this->fail('storage_policy', '存储配置格式无效');
        $trial = $raw['trial_scope'] ?? [];
        if (!is_array($trial)) $this->fail('trial_scope', '试用范围格式无效');
        $scope = [];
        foreach (['zone_ids', 'flavor_ids', 'line_ids', 'image_ids', 'disk_type_ids', 'protection_ids'] as $key) {
            $ids = $trial[$key] ?? [];
            if (!is_array($ids) || count($ids) > 500) $this->fail('trial_scope', '试用资源范围无效');
            $scope[$key] = array_values(array_unique(array_map(fn (mixed $v): int => $this->integer($v, 1, PHP_INT_MAX, 'trial_scope'), $ids)));
        }
        foreach (['max_system_disk_gib' => 65536, 'max_data_disk_gib' => 65536, 'max_data_disks' => 32, 'max_egress_mbps' => 1000000, 'max_ingress_mbps' => 1000000, 'max_ip_count' => 64] as $key => $max) $scope[$key] = $this->integer($trial[$key] ?? $max, 0, $max, 'trial_scope');
        $scope['concurrent_limit'] = $this->integer($trial['concurrent_limit'] ?? 1, 1, 100, 'trial_scope');
        $scope['cooldown_hours'] = $this->integer($trial['cooldown_hours'] ?? 0, 0, 8760, 'trial_scope');
        return $periodPolicy + ['schema_version' => '2.0.0', 'billing_mode' => 'prepaid',
            'storage_policy' => ['default_type_id' => $this->integer($storage['default_type_id'] ?? 0, 0, PHP_INT_MAX, 'storage_policy'),
                'default_size_gib' => $this->integer($storage['default_size_gib'] ?? 40, 1, 65536, 'storage_policy'),
                'max_data_disks' => $this->integer($storage['max_data_disks'] ?? 8, 0, 32, 'storage_policy')], 'trial_scope' => $scope];
    }

    /** @return list<array<string, mixed>> 每个可用区内月数唯一的周期；只保留标准字段。 */
    private function normalizeDurations(mixed $durations, string $field): array
    {
        if (!is_array($durations) || !array_is_list($durations) || count($durations) < 1 || count($durations) > 24) $this->fail($field, '请设置1至24种付费周期');
        $periods = []; $seen = [];
        foreach ($durations as $item) {
            if (!is_array($item)) $this->fail($field, '付费周期格式无效');
            $months = $this->integer($item['months'] ?? null, 1, 120, $field);
            if (isset($seen[$months])) $this->fail($field, '同一可用区的付费周期月数不能重复');
            $seen[$months] = true;
            $label = $item['label'] ?? $months . '个月';
            if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 32) $this->fail($field, '周期名称须为1至32个字符');
            $kind = $item['discount_kind'] ?? 'rate';
            $period = ['months' => $months, 'label' => trim($label), 'discount_kind' => $kind];
            if ($kind === 'rate') $period['rate_bps'] = $this->integer($item['rate_bps'] ?? 10000, 1, 10000, $field);
            elseif ($kind === 'billed_months') $period['billed_months'] = $this->integer($item['billed_months'] ?? $months, 1, $months, $field);
            else $this->fail($field, '周期优惠方式无效');
            $periods[] = $period;
        }
        return $periods;
    }

    /** @param mixed $value 输入 @param int $min 下界 @param int $max 上界 @return int 已检查的整数 */
    private function integer(mixed $value, int $min, int $max, string $field): int
    {
        if (!is_int($value) || $value < $min || $value > $max) $this->fail($field, '数量须为范围内的整数');
        return $value;
    }

    /** @param string $message 校验原因 @return never */
    private function fail(string $field, string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => [$message]]]);
    }
}
