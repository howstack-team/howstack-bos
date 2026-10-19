<?php

declare(strict_types=1);

namespace app\modules\compute\validation;

use app\modules\compute\domain\ComputeResources;
use support\validation\Validator;

/** 云服务器销售草稿的固定结构及白名单。允许保存尚未完成的草稿。 */
final class CloudOfferValidator
{
    /** 当前后台仅接受包年包月配置；旧结构的解析仅供已有数据和历史验收使用。
     * @param array<string, mixed> $input 当前 HTTP 请求
     * @return array<string, mixed> 当前草稿
     */
    public function validateCurrent(array $input): array
    {
        $validator = Validator::make($input, [
            'draft' => 'required|array',
            'draft.schema_version' => 'required|in:2.0.0',
        ], null, ['draft.schema_version' => '配置版本']);
        if ($validator->fails()) {
            throw new \app\exception\BusinessException(
                '当前仅接受包年包月 v2 配置',
                \app\shared\http\ApiErrorCode::VALIDATION_FAILED,
                422,
                ['errors' => $validator->errors()->toArray()],
            );
        }
        return $this->validate($input);
    }

    /** @param array<string, mixed> $input 请求 @return array<string, mixed> 标准草稿 */
    public function validate(array $input): array
    {
        $defaults = ['resource_ids' => array_fill_keys(ComputeResources::KINDS, []), 'cycles' => ['month'], 'quantity_limit' => 10,
            'setup_minor' => 0, 'system_disk_included_gib' => 0, 'provider_connection_id' => 0, 'delivery_rule_id' => 0, 'network_policy_id' => 0, 'price_overrides' => [],
            'network' => ['bandwidth_min' => 1, 'bandwidth_max' => 100, 'bandwidth_step' => 1, 'ip_min' => 1, 'ip_max' => 1,
                'bandwidth_billing_mode' => 'flat', 'bandwidth_tiers' => [],
                'bandwidth_prices' => array_fill_keys(ComputeResources::CYCLES, 0), 'ip_prices' => array_fill_keys(ComputeResources::CYCLES, 0)],
            'trial_policy' => ['enabled' => false, 'hours' => 24, 'limit_per_member' => 1],
            'refund_policy' => ['enabled' => false, 'window_hours' => 168, 'prorated' => true, 'keep_setup_fee' => true],
            'renewal_policy' => ['pricing' => 'current', 'auto_renew_enabled' => false]];
        Validator::make($input, ['draft' => 'required|array', 'draft.resource_ids' => 'sometimes|array',
            'draft.network' => 'sometimes|array', 'draft.trial_policy' => 'sometimes|array',
            'draft.refund_policy' => 'sometimes|array', 'draft.renewal_policy' => 'sometimes|array'])->validate();
        $raw = $input['draft'];
        if (($raw['schema_version'] ?? null) === '2.0.0') {
            // 线路已经完整拥有网络规则；旧单带宽和策略指针不再进入当前草稿。
            unset($raw['network'], $raw['network_policy_id']);
        }
        if (isset($raw['schema_version']) && !in_array($raw['schema_version'], ['1.3.0', '2.0.0'], true)) throw new \app\exception\BusinessException('不支持的销售配置版本', \app\shared\http\ApiErrorCode::VALIDATION_FAILED, 422);
        $draft = array_replace_recursive($defaults, $raw);
        $draft['cycles'] = $raw['cycles'] ?? $defaults['cycles'];
        foreach (ComputeResources::KINDS as $kind) $draft['resource_ids'][$kind] = $raw['resource_ids'][$kind] ?? [];
        $draft['price_overrides'] = $raw['price_overrides'] ?? [];
        $draft['network']['bandwidth_tiers'] = $raw['network']['bandwidth_tiers'] ?? [];
        $rules = ['resource_ids' => 'required|array', 'cycles' => 'required|array|min:1|max:4', 'cycles.*' => 'required|in:hour,day,month,year',
            'quantity_limit' => 'required|integer|min:1|max:100', 'setup_minor' => 'required|integer|min:0|max:100000000',
            'system_disk_included_gib' => 'required|integer|min:0|max:65536',
            'provider_connection_id' => 'required|integer|min:0', 'delivery_rule_id' => 'required|integer|min:0', 'network_policy_id' => 'required|integer|min:0', 'price_overrides' => 'array',
            'network' => 'required|array', 'network.bandwidth_prices' => 'required|array', 'network.ip_prices' => 'required|array',
            'trial_policy' => 'required|array', 'refund_policy' => 'required|array', 'renewal_policy' => 'required|array',
            'network.bandwidth_min' => 'required|integer|min:0|max:1000000', 'network.bandwidth_max' => 'required|integer|gte:network.bandwidth_min|max:1000000',
            'network.bandwidth_step' => 'required|integer|min:1|max:1000000', 'network.ip_min' => 'required|integer|min:0|max:64',
            'network.bandwidth_billing_mode' => 'required|in:flat,graduated,volume', 'network.bandwidth_tiers' => 'present|array|max:40',
            'network.bandwidth_tiers.*.up_to_mbps' => 'present|nullable|integer|min:1|max:1000000', 'network.bandwidth_tiers.*.prices' => 'required|array',
            'network.ip_max' => 'required|integer|gte:network.ip_min|max:64',
            'trial_policy.enabled' => 'required|boolean', 'trial_policy.hours' => 'required|integer|min:1|max:720', 'trial_policy.limit_per_member' => 'required|integer|min:1|max:10',
            'refund_policy.enabled' => 'required|boolean', 'refund_policy.window_hours' => 'required|integer|min:1|max:8760',
            'refund_policy.prorated' => 'required|boolean', 'refund_policy.keep_setup_fee' => 'required|boolean',
            'renewal_policy.pricing' => 'required|in:current', 'renewal_policy.auto_renew_enabled' => 'required|boolean'];
        foreach (ComputeResources::KINDS as $kind) {
            $rules['resource_ids.' . $kind] = 'present|array|max:500';
            $rules['resource_ids.' . $kind . '.*'] = 'required|integer|min:1';
        }
        foreach (ComputeResources::CYCLES as $cycle) {
            foreach (['bandwidth_prices', 'ip_prices'] as $field) $rules['network.' . $field . '.' . $cycle] = 'required|integer|min:0|max:100000000';
            $rules['network.bandwidth_tiers.*.prices.' . $cycle] = 'sometimes|integer|min:0|max:100000000';
            foreach (['flavors', 'disk_types', 'protections'] as $kind) $rules['price_overrides.' . $kind . '.*.' . $cycle] = 'sometimes|integer|min:0|max:100000000';
        }
        foreach (['flavors', 'disk_types', 'protections'] as $kind) {
            $rules['price_overrides.' . $kind] = 'sometimes|array|max:500';
            $rules['price_overrides.' . $kind . '.*'] = 'array';
        }
        $data = Validator::make($draft, $rules, null, ['system_disk_included_gib' => '系统盘赠送容量'])->validate();
        $clean = $defaults;
        foreach (ComputeResources::KINDS as $kind) $clean['resource_ids'][$kind] = array_values(array_unique(array_map('intval', $data['resource_ids'][$kind])));
        $clean['cycles'] = array_values(array_unique($data['cycles']));
        foreach (['setup_minor', 'system_disk_included_gib', 'quantity_limit', 'provider_connection_id', 'delivery_rule_id', 'network_policy_id'] as $field) $clean[$field] = (int) $data[$field];
        foreach (['bandwidth_min', 'bandwidth_max', 'bandwidth_step', 'ip_min', 'ip_max'] as $field) $clean['network'][$field] = (int) $data['network'][$field];
        foreach (['bandwidth_prices', 'ip_prices'] as $field) $clean['network'][$field] = array_map('intval', array_intersect_key($data['network'][$field], array_flip(ComputeResources::CYCLES)));
        $clean['network']['bandwidth_billing_mode'] = $data['network']['bandwidth_billing_mode'];
        $clean['network']['bandwidth_tiers'] = array_map(static fn (array $tier): array => ['up_to_mbps' => $tier['up_to_mbps'] === null ? null : (int) $tier['up_to_mbps'],
            'prices' => array_map('intval', array_intersect_key($tier['prices'], array_flip(ComputeResources::CYCLES)))], $data['network']['bandwidth_tiers']);
        foreach (['trial_policy', 'refund_policy', 'renewal_policy'] as $policy) foreach ($defaults[$policy] as $field => $value) {
            $clean[$policy][$field] = is_bool($value) ? (bool) $data[$policy][$field] : (is_int($value) ? (int) $data[$policy][$field] : $data[$policy][$field]);
        }
        foreach (['flavors', 'disk_types', 'protections'] as $kind) foreach ($data['price_overrides'][$kind] ?? [] as $id => $prices) {
            if (!ctype_digit((string) $id) || (int) $id < 1) continue;
            $clean['price_overrides'][$kind][(int) $id] = array_map('intval', array_intersect_key($prices, array_flip(ComputeResources::CYCLES)));
        }
        $main = Validator::make($input + ['description' => '', 'revision' => 0], ['name' => 'required|string|max:128',
            'code' => 'required|string|regex:/^[a-z0-9][a-z0-9_-]*$/|max:64', 'description' => 'string|max:1000', 'revision' => 'integer|min:0'])->validate();
        if (($raw['schema_version'] ?? null) === '2.0.0') {
            $bindings = Validator::make($raw + ['zone_provider_bindings' => []], [
                'zone_provider_bindings' => 'present|array|max:500',
                'zone_provider_bindings.*.zone_id' => 'required|integer|min:1|distinct',
                'zone_provider_bindings.*.connection_id' => 'required|integer|min:0',
                'zone_provider_bindings.*.parameter_mapping' => 'sometimes|array|max:64',
            ])->validate()['zone_provider_bindings'];
            $clean['zone_provider_bindings'] = array_values(array_map(static fn (array $binding): array => [
                'zone_id' => (int) $binding['zone_id'],
                'connection_id' => (int) $binding['connection_id'],
                'parameter_mapping' => (new \app\modules\provider\domain\ProviderParameterMapping())->normalize($binding['parameter_mapping'] ?? []),
            ], $bindings));
            $clean = array_replace($clean, (new \app\modules\compute\domain\PrepaidSalesConfiguration())->normalize($raw));
            $clean['cycles'] = ['month'];
            unset($clean['network'], $clean['network_policy_id']);
            unset($clean['resource_ids']['network_policies']);
        }
        return ['name' => trim($main['name']), 'code' => $main['code'], 'description' => $main['description'], 'revision' => (int) $main['revision'], 'draft' => $clean];
    }
}
