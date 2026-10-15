<?php

declare(strict_types=1);
namespace app\modules\admin\auth\validation;
use app\modules\admin\auth\exception\AdminAuthenticationException;
/** 只接收当前用例需要的字段；验证失败统一映射到字段。 */
final class AdminMfaValidator
{
    /** @return array{current_password:string,code:string,recovery:bool,setup_id:string} */
    public static function input(array $input, bool $password = true, bool $code = true, bool $setup = false): array
    {
        $errors = [];
        $pass = $input['current_password'] ?? '';
        $value = $input['code'] ?? '';
        $recovery = $input['recovery'] ?? false;
        $setupId = $input['setup_id'] ?? '';
        if ($password && (!is_string($pass) || $pass === '' || mb_strlen($pass) > 128)) $errors['current_password'] = ['请输入当前密码，最多128个字符'];
        if (!is_bool($recovery)) $errors['recovery'] = ['验证码类型无效'];
        if ($code && (!is_string($value) || !preg_match($recovery === true ? '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{8}){3})$/D' : '/^[0-9]{6}$/D', $value))) $errors['code'] = [$recovery === true ? '请输入有效的恢复码' : '请输入6位验证器验证码'];
        if ($setup && (!is_string($setupId) || !preg_match('/^[a-f0-9]{64}$/D', $setupId))) $errors['setup_id'] = ['绑定已失效，请重新开始'];
        if ($errors !== []) throw AdminAuthenticationException::validationFailed($errors);
        return ['current_password' => is_string($pass) ? $pass : '', 'code' => is_string($value) ? $value : '', 'recovery' => $recovery === true, 'setup_id' => is_string($setupId) ? $setupId : ''];
    }
}
