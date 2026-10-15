<?php

declare(strict_types=1);

namespace app\modules\member\validation;

use support\validation\Validator;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

final class MemberOAuthValidator
{
    /** @return array{provider: string, remember_me: bool} */
    public static function start(array $input): array
    {
        $result = self::validate(['provider' => $input['provider'] ?? null, 'remember_me' => $input['remember_me'] ?? false],
            ['provider' => 'required|string|in:qq,wechat', 'remember_me' => 'required|boolean']);
        return ['provider' => $result['provider'], 'remember_me' => (bool) $result['remember_me']];
    }

    /** @return array{state: string, code: string} */
    public static function complete(array $input): array
    {
        return self::validate(['state' => $input['state'] ?? null, 'code' => $input['code'] ?? null],
            ['state' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,512}$/D']]);
    }

    private static function validate(array $values, array $rules): array
    {
        $validator = Validator::make($values, $rules)->toIlluminate();
        if ($validator->fails()) throw new BusinessException('请检查授权参数', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $validator->errors()->toArray()]);
        return $validator->validated();
    }
}
