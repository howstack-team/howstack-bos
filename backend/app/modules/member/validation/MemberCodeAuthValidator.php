<?php

declare(strict_types=1);

namespace app\modules\member\validation;

use support\validation\Validator;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

final class MemberCodeAuthValidator
{
    /** @return array<string, mixed> 登录、找回请求只接受已定义字段 */
    public static function input(array $input, bool $login, bool $complete): array
    {
        $field = $login ? 'mobile' : 'account';
        $rules = [$field => $login ? ['required', 'string', 'regex:/^1[3-9][0-9]{9}$/D']
            : ['required', 'string', 'max:254', 'regex:/^(?:1[3-9][0-9]{9}|[^\s@]+@[^\s@]+\.[^\s@]+)$/D']];
        $values = [$field => $input[$field] ?? null];
        if (is_string($values[$field])) $values[$field] = strtolower(trim($values[$field]));
        if ($complete) {
            $rules += ['code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
                'challenge_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D']];
            $values += ['code' => $input['code'] ?? null, 'challenge_id' => $input['challenge_id'] ?? null];
            if ($login) {
                $rules['remember_me'] = 'required|boolean';
                $values['remember_me'] = $input['remember_me'] ?? false;
            } else {
                $rules['password'] = 'required|string|min:8|max:72';
                $values['password'] = $input['password'] ?? null;
            }
        }
        return self::validate($values, $rules);
    }
    public static function passwordLogin(array $input): array
    {
        $values = ['account' => $input['account'] ?? null, 'password' => $input['password'] ?? null,
            'remember_me' => $input['remember_me'] ?? false];
        if (is_string($values['account'])) $values['account'] = strtolower(trim($values['account']));
        $result = self::validate($values, [
            'account' => ['required', 'string', 'max:254', 'regex:/^(?:\+?[0-9]{6,20}|[^\s@]+@[^\s@]+\.[^\s@]+)$/D'],
            'password' => 'required|string|min:1|max:72', 'remember_me' => 'required|boolean',
        ]);
        if (strlen($result['password']) > 72) {
            throw new BusinessException('请检查登录信息', ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => ['password' => ['密码最多72字节']]]);
        }
        return $result;
    }

    private static function validate(array $values, array $rules): array
    {
        $validator = Validator::make($values, $rules)->toIlluminate();
        if ($validator->fails()) {
            throw new BusinessException('请检查认证信息', ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => $validator->errors()->toArray()]);
        }
        return $validator->validated();
    }
}
