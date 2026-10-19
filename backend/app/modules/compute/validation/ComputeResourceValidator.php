<?php

declare(strict_types=1);

namespace app\modules\compute\validation;

use app\exception\BusinessException;
use app\modules\compute\domain\ComputeResources;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 对每种资源使用确定的字段规则，计价只接收整数分。 */
final class ComputeResourceValidator
{
    /** @param string $kind 种类 @param array<string, mixed> $input 请求体 @return array<string, mixed> 白名单字段 */
    public function validate(string $kind, array $input): array
    {
        $rules = ['code' => 'required|string|regex:/^[a-zA-Z0-9_-]+$/|max:64', 'name' => 'required|string|max:128',
            'description' => 'string|max:1000', 'sort' => 'integer|min:0|max:100000', 'status' => 'required|integer|in:0,1'];
        $specific = match ($kind) {
            'regions' => ['country_code' => 'nullable|string|regex:/^[A-Z]{2}$/'],
            'image_types' => ['icon_url' => 'nullable|string|max:500'],
            'flavor_groups' => ['cpu_model' => 'string|max:128', 'base_clock_mhz' => 'nullable|integer|min:1|max:20000', 'boost_clock_mhz' => 'nullable|integer|min:1|max:20000'],
            'zones' => ['region_id' => 'required|integer|min:1'],
            'lines' => ['test_ip' => 'nullable|string|ip|max:45', 'network_configuration' => 'nullable|array', 'zone_id' => 'required|integer|min:1'],
            'flavors' => ['zone_id' => 'required|integer|min:1', 'group_id' => 'required|integer|min:1', 'cpu' => 'required|integer|min:1|max:4096',
                'memory_mib' => 'required|integer|min:128|max:16777216', 'architecture' => 'required|in:x86_64,aarch64', 'prices' => 'required|array'],
            'images' => ['default_username' => 'string|max:64', 'default_port' => 'integer|min:1|max:65535', 'zone_id' => 'required|integer|min:1', 'type_id' => 'required|integer|min:1', 'architecture' => 'required|in:x86_64,aarch64',
                'min_disk_gib' => 'required|integer|min:1|max:65536', 'min_cpu' => 'required|integer|min:1|max:4096', 'min_memory_mib' => 'required|integer|min:128|max:16777216',
                'login_methods' => 'required|array|min:1|max:2', 'login_methods.*' => 'required|in:password,ssh_key'],
            'disk_types' => ['zone_id' => 'required|integer|min:1', 'min_gib' => 'required|integer|min:1|max:65536', 'max_gib' => 'required|integer|gte:min_gib|max:65536',
                'step_gib' => 'required|integer|min:1|max:65536', 'max_count' => 'required|integer|min:0|max:32', 'prices' => 'required|array',
                'read_mbps' => 'nullable|integer|min:1|max:1000000', 'write_mbps' => 'nullable|integer|min:1|max:1000000',
                'read_iops' => 'nullable|integer|min:1|max:100000000', 'write_iops' => 'nullable|integer|min:1|max:100000000'],
            'protections' => ['capacity_gbps' => 'integer|min:0|max:1000000', 'line_id' => 'required|integer|min:1', 'prices' => 'required|array'],
            default => throw new BusinessException('配置资源类型不存在', ApiErrorCode::NOT_FOUND, 404),
        };
        // 缺省字段不写入，兼容旧客户端；显式空值用于清除展示信息。
        $metadataField = match ($kind) { 'regions' => 'country_code', 'lines' => 'test_ip', default => null };
        if ($metadataField !== null && array_key_exists($metadataField, $input)) {
            if (is_string($input[$metadataField])) {
                $input[$metadataField] = trim($input[$metadataField]);
                if ($kind === 'regions') $input[$metadataField] = strtoupper($input[$metadataField]);
            }
            if ($input[$metadataField] === null) $input[$metadataField] = '';
        }
        if ($kind === 'images') $input += ['min_cpu' => 1, 'min_memory_mib' => 128, 'default_username' => 'root', 'default_port' => 22];
        if ($kind === 'protections') $input += ['capacity_gbps' => 0];
        if ($kind === 'flavor_groups' && isset($input['base_clock_mhz'], $input['boost_clock_mhz'])) $specific['boost_clock_mhz'] .= '|gte:base_clock_mhz';
        // 新接口使用多关联；旧单值请求按一项关联转换，原字段作为兼容主关联。
        $relation = in_array($kind, ['lines', 'flavors', 'images', 'disk_types'], true) ? ['zone_id', 'zone_ids'] : ($kind === 'protections' ? ['line_id', 'line_ids'] : null);
        if ($relation) {
            [$scalar, $plural] = $relation;
            $input[$plural] = $input[$plural] ?? (isset($input[$scalar]) ? [$input[$scalar]] : []);
            unset($specific[$scalar]);
            $specific[$plural] = 'required|array|min:1|max:500';
            $specific[$plural . '.*'] = 'required|integer|min:1|distinct';
        }
        if (in_array($kind, ['flavors', 'disk_types'], true)) {
            $specific['zone_prices'] = 'sometimes|array|max:500';
        }
        if (isset($specific['prices'])) foreach (ComputeResources::CYCLES as $cycle) $specific['prices.' . $cycle] = 'sometimes|integer|min:0|max:100000000';
        $data = Validator::make($input + ['description' => '', 'sort' => 0], $rules + $specific, [
            'country_code.regex' => '国家代码须为两位英文字母，例如 CN、US',
            'test_ip.ip' => '测试 IP 须为有效的 IPv4 或 IPv6 地址',
        ])->validate();
        $data = array_intersect_key($data, array_filter($rules + $specific, static fn (string $key): bool => !str_contains($key, '.'), ARRAY_FILTER_USE_KEY));
        if (isset($data['prices'])) $data['prices'] = array_map('intval', array_intersect_key($data['prices'], array_flip(ComputeResources::CYCLES)));
        if ($relation) {
            $data[$plural] = array_values(array_map('intval', $data[$plural]));
            $data[$scalar] = $data[$plural][0];
        }
        foreach (['default_port', 'capacity_gbps', 'region_id', 'zone_id', 'line_id', 'group_id', 'type_id', 'cpu', 'memory_mib', 'min_cpu', 'min_memory_mib', 'base_clock_mhz', 'boost_clock_mhz', 'read_mbps', 'write_mbps', 'read_iops', 'write_iops', 'min_disk_gib', 'min_gib', 'max_gib', 'step_gib', 'max_count', 'bandwidth_min', 'bandwidth_max', 'bandwidth_step', 'ip_min', 'ip_max', 'status', 'sort'] as $key) {
            if (isset($data[$key])) $data[$key] = (int) $data[$key];
        }
        if ($kind === 'image_types') {
            $icon = $data['icon_url'] ?? '';
            $local = str_starts_with($icon, '/') && !str_starts_with($icon, '//');
            $https = filter_var($icon, FILTER_VALIDATE_URL) !== false && parse_url($icon, PHP_URL_SCHEME) === 'https';
            if ($icon !== '' && (str_contains($icon, chr(92)) || preg_match('/\s/u', $icon) || (!$local && !$https))) {
                throw new BusinessException('图标须为HTTPS地址或站内路径', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['icon_url' => ['图标须为HTTPS地址或站内路径']]]);
            }
            $data['icon_url'] = $icon;
        }
        if ($kind === 'lines' && isset($data['network_configuration'])) $data['network_configuration'] = (new \app\modules\compute\domain\LineNetworkConfiguration())->normalize($data['network_configuration']);
        if (in_array($kind, ['flavors', 'disk_types'], true) && array_key_exists('zone_prices', $input)) {
            $data['zone_prices'] = (new \app\modules\compute\domain\ResourceZonePrices())->normalize($input['zone_prices'], $data['zone_ids']);
        }
        return $data;
    }
}
