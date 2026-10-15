<?php

declare(strict_types=1);

namespace app\modules\admin\profile\validation;

use app\exception\BusinessException;
use support\validation\Validator;

/** 个人资料与密码的独立白名单，不复用管理员管理表单。 */
final class AdminProfileValidator
{
    /** @param array<string, mixed> $input @return array{employee_no: ?string, mobile: ?string, email: ?string} */
    public static function profile(array $input): array
    {
        $data = [];
        foreach (['employee_no', 'mobile', 'email'] as $field) {
            $value = $input[$field] ?? null;
            $data[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        return Validator::make($data, [
            'employee_no' => 'nullable|string|max:32',
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'email' => 'nullable|string|email|max:254',
        ], [
            'employee_no.max' => '工号不能超过 32 个字符',
            'mobile.regex' => '请输入有效的手机号',
            'email.email' => '请输入有效的邮箱地址',
            'email.max' => '邮箱地址不能超过 254 个字符',
        ])->validate();
    }

    /** @param array<string, mixed> $input @return array{current_password: string, new_password: string} */
    public static function password(array $input): array
    {
        $data = array_intersect_key($input, array_flip([
            'current_password', 'new_password', 'new_password_confirmation',
        ]));
        $validated = Validator::make($data, [
            'current_password' => 'required|string|max:128',
            'new_password' => 'required|string|min:8|max:72|confirmed',
            'new_password_confirmation' => 'required|string|max:72',
        ], [
            'current_password.required' => '请输入原密码',
            'new_password.required' => '请输入新密码',
            'new_password.min' => '新密码至少需要 8 个字符',
            'new_password.max' => '新密码不能超过 72 个字符',
            'new_password.confirmed' => '两次输入的新密码不一致',
            'new_password_confirmation.required' => '请再次输入新密码',
        ])->validate();
        // PASSWORD_DEFAULT 当前采用 bcrypt；禁止静默截断多字节密码。
        if (strlen($validated['new_password']) > 72) {
            throw new BusinessException('新密码不能超过 72 字节，请减少字符数');
        }

        return [
            'current_password' => $validated['current_password'],
            'new_password' => $validated['new_password'],
        ];
    }
}
