<?php

declare(strict_types=1);

namespace app\modules\member\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 注册输入白名单；先检查类型，再规范化邮箱与手机号。 */
final class MemberRegistrationValidator
{
    public static function input(array $input, bool $register): array
    {
        $method = self::validate(['method' => $input['method'] ?? null], ['method' => 'required|string|in:mobile,email'])['method'];
        $account = trim(self::validate(['account' => $input['account'] ?? null], ['account' => 'required|string|max:254'])['account']);
        if ($method === 'email') $account = strtolower($account);
        $rules = ['method' => 'required|in:mobile,email', 'account' => $method === 'email' ? 'required|string|email|max:254' : ['required', 'string', 'regex:/^1[3-9][0-9]{9}$/']];
        $values = ['method' => $method, 'account' => $account];
        if ($register) {
            $values += ['password' => $input['password'] ?? null, 'code' => $input['code'] ?? '', 'challenge_id' => $input['challenge_id'] ?? ''];
            $rules += ['password' => 'required|string|min:8|max:72', 'code' => ['present', 'string', 'regex:/^(?:[0-9]{6})?$/'], 'challenge_id' => ['present', 'string', 'regex:/^(?:[a-f0-9]{32})?$/']];
        }
        return self::validate($values, $rules);
    }

    private static function validate(array $values, array $rules): array
    {
        $validator = Validator::make($values, $rules)->toIlluminate();
        if ($validator->fails()) {
            throw new BusinessException('请检查注册信息', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $validator->errors()->toArray()]);
        }
        return $validator->validated();
    }
}
