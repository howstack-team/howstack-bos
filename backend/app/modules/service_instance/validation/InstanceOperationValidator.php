<?php

declare(strict_types=1);

namespace app\modules\service_instance\validation;

use support\validation\Validator;

/** 接口白名单；对象归属、插件能力和实际镜像要求继续由应用层核实。 */
final class InstanceOperationValidator
{
    /**
     * @param mixed $id 路径
     * @param array<string,mixed> $body 请求
     * @param bool $submit 是否写入
     * @return array<string,mixed> 受控输入
     */
    public static function validate(mixed $id, #[\SensitiveParameter] array $body = [], bool $submit = false): array
    {
        $rules = ['id' => 'required|integer|min:1'];
        if ($submit) {
            $rules += ['action' => 'required|in:power_on,power_off,reboot,reinstall,reset_password',
                'request_key' => 'required|string|min:16|max:64|regex:/^[a-zA-Z0-9_-]+$/',
                'acknowledged' => 'required|accepted'];
            if (in_array($body['action'] ?? null, ['reinstall', 'reset_password'], true)) {
                $rules['password'] = 'required|string|min:8|max:64|regex:/^[\x21-\x7E]+$/';
            }
            if (($body['action'] ?? null) === 'reinstall') {
                $rules['image_id'] = 'required|integer|min:1';
            }
        }
        $validator = Validator::make(['id' => $id] + $body, $rules, attributes: [
            'id' => '实例', 'action' => '操作', 'request_key' => '请求标识', 'acknowledged' => '操作确认',
            'password' => '新登录密码', 'image_id' => '重装镜像',
        ]);
        if (!$validator->passes()) {
            throw new \app\exception\BusinessException('请检查填写的操作信息', \app\shared\http\ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => $validator->errors()->toArray()]);
        }
        $data = $validator->validate();
        $data['id'] = (int) $data['id'];
        if (isset($data['image_id'])) {
            $data['image_id'] = (int) $data['image_id'];
        }
        return array_intersect_key($data, $rules);
    }
}
