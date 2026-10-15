<?php

declare(strict_types=1);

namespace app\modules\compute\validation;

use support\validation\Validator;

/** 只接受标准配置，不允许传入供应参数、密码、价格、状态或未声明字段。 */
final class ComputePurchaseValidator
{
    public static function clean(mixed $input): array
    {
        $rules = ['selection' => 'present|array', 'selection.duration_months' => 'sometimes|integer|min:1|max:120',
            'selection.billing_mode' => 'sometimes|in:prepaid', 'selection.network.billing_mode' => 'sometimes|in:fixed',
            'selection.billing_cycle' => 'sometimes|in:month,year', 'selection.quantity' => 'sometimes|integer|min:1|max:100',
            'selection.login_method' => 'sometimes|in:password', 'selection.data_disks' => 'sometimes|array|max:32',
            'selection.system_disk' => 'sometimes|array', 'selection.network' => 'sometimes|array', 'selection.data_disks.*' => 'required|array'];
        $ids = ['region_id','zone_id','line_id','flavor_id','image_id','protection_id','flavor_group_id','image_type_id'];
        foreach ($ids as $field) $rules['selection.' . $field] = 'nullable|integer|min:0';
        foreach (['system_disk.type_id','system_disk.size_gib','network.egress_mbps','network.ingress_mbps','network.bandwidth_mbps','network.ipv4_count','data_disks.*.type_id','data_disks.*.size_gib'] as $field) $rules['selection.' . $field] = 'sometimes|integer|min:0|max:1000000';
        $v = Validator::make(['selection' => $input], $rules)->validate()['selection'];
        $selection = array_intersect_key($v, array_flip(array_merge($ids, ['duration_months','billing_mode','billing_cycle','quantity','login_method','system_disk','data_disks','network'])));
        foreach (array_merge($ids, ['duration_months','quantity']) as $field) if (isset($selection[$field])) $selection[$field] = (int) $selection[$field];
        foreach (['system_disk' => ['type_id','size_gib'], 'network' => ['egress_mbps','ingress_mbps','bandwidth_mbps','ipv4_count']] as $field => $keys) if (isset($selection[$field])) $selection[$field] = array_map('intval', array_intersect_key($selection[$field], array_flip($keys)));
        if (isset($v['network']['billing_mode'])) $selection['network']['billing_mode'] = $v['network']['billing_mode'];
        if (isset($selection['data_disks'])) $selection['data_disks'] = array_values(array_map(static fn (array $disk): array => array_map('intval', array_intersect_key($disk, array_flip(['type_id','size_gib']))), $selection['data_disks']));
        return $selection;
    }
}
