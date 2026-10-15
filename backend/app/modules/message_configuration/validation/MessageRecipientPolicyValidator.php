<?php

declare(strict_types=1);

namespace app\modules\message_configuration\validation;

use support\validation\Validator;
use app\exception\BusinessException;

/** 数量有界且只能选启用的系统部门、角色或管理员；不接受任意邮箱。 */
final class MessageRecipientPolicyValidator
{
    public function values(mixed $input): array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['mode', 'ids']) !== []) {
            throw new BusinessException('接收对象配置无效');
        }
        $values = Validator::make($input, ['mode' => 'required|in:founder,departments,roles,members',
            'ids' => 'present|array|max:100', 'ids.*' => 'integer|min:1|max:2147483647|distinct'])->validate();
        $ids = array_map(intval(...), $values['ids']);
        if (($values['mode'] === 'founder' && $ids !== []) || ($values['mode'] !== 'founder' && $ids === [])) {
            throw new BusinessException('请选择消息接收对象');
        }
        return ['mode' => $values['mode'], 'ids' => $ids];
    }
}
