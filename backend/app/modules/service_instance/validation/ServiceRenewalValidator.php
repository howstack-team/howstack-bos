<?php

declare(strict_types=1);

namespace app\modules\service_instance\validation;

use support\validation\Validator;

/** 仅接受期数、期望金额和幂等键，会员与实例归属由服务端认证决定。 */
final class ServiceRenewalValidator
{
    /**
     * @param mixed $id 路径
     * @param array<string,mixed> $body 请求
     * @param bool $create 是否创建订单
     * @return array<string,mixed> 转换后的白名单输入
     */
    public static function validate(mixed $id, array $body, bool $create = false): array
    {
        $rules = ['id' => 'required|integer|min:1', 'months' => 'required|integer|min:0|max:120'];
        if ($create) {
            $rules['months'] = 'required|integer|min:1|max:120';
            $rules += ['request_key' => 'required|string|min:16|max:64|regex:/^[a-zA-Z0-9_-]+$/', 'expected_amount_minor' => 'required|integer|min:1|max:100000000'];
        }
        $data = Validator::make(['id' => $id] + $body, $rules)->validate();
        $result = ['id' => (int) $data['id'], 'months' => (int) $data['months']];
        if ($create) {
            $result += ['request_key' => $data['request_key'], 'expected_amount_minor' => (int) $data['expected_amount_minor']];
        }
        return $result;
    }
}
