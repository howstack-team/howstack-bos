<?php

declare(strict_types=1);

namespace app\modules\product\validation;

use support\validation\Validator;
use app\modules\compute\domain\ComputeResources;

final class CloudDeliveryRuleValidator
{
    /** @param array<string, mixed> $input HTTP 输入 @return array<string, mixed> 白名单配置。 */
    public function validate(array $input): array
    {
        $rules = [
            'name' => 'required|string|max:128', 'description' => 'string|max:1000', 'connection_id' => 'required|integer|min:1',
            'line_ids' => 'present|array|max:500', 'line_ids.*' => 'required|integer|min:1', 'priority' => 'integer|min:0|max:100000',
            'status' => 'required|integer|in:0,1', 'revision' => 'integer|min:0',
            'resource_ids' => 'sometimes|array', 'parameter_mapping' => 'sometimes|array|max:64',
        ];
        foreach (ComputeResources::KINDS as $kind) {
            $rules['resource_ids.' . $kind] = 'sometimes|array|max:500';
            $rules['resource_ids.' . $kind . '.*'] = 'required|integer|min:1|distinct';
        }
        $data = Validator::make($input + ['description' => '', 'line_ids' => [], 'priority' => 0, 'revision' => 0], $rules)->validate();
        foreach (['connection_id', 'priority', 'status', 'revision'] as $key) $data[$key] = (int) $data[$key];
        $data['line_ids'] = array_values(array_unique(array_map('intval', $data['line_ids'])));
        if (isset($data['resource_ids'])) {
            $data['resource_ids'] = array_intersect_key($data['resource_ids'], array_flip(ComputeResources::KINDS));
            foreach ($data['resource_ids'] as &$ids) $ids = array_values(array_map('intval', $ids));
            unset($ids);
            $data['resource_ids']['lines'] = $data['line_ids'];
        }
        $data['parameter_mapping'] = (new \app\modules\provider\domain\ProviderParameterMapping())->normalize($data['parameter_mapping'] ?? []);
        return array_intersect_key($data, array_flip(['parameter_mapping', 'name', 'description', 'connection_id', 'line_ids', 'resource_ids', 'priority', 'status', 'revision']));
    }
}
