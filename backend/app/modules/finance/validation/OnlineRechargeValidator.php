<?php

declare(strict_types=1);

namespace app\modules\finance\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

final class OnlineRechargeValidator
{
    public static function create(array $input): array
    {
        if (!is_int($input['amount_minor'] ?? null)) throw new BusinessException('请输入正确充值金额', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['amount_minor' => ['金额必须为整数分']]]);
        return self::validate(array_intersect_key($input, array_flip(['channel_id', 'amount_minor', 'request_key'])),
            ['channel_id' => 'required|integer|min:1', 'amount_minor' => 'required|integer|min:1|max:100000000', 'request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/D']]);
    }
    public static function page(array $input): array { return self::validate(['page' => $input['page'] ?? 1, 'page_size' => $input['page_size'] ?? 20], ['page' => 'required|integer|min:1', 'page_size' => 'required|integer|min:1|max:100']); }
    public static function id(mixed $id): int { return (int) self::validate(['id' => $id], ['id' => 'required|integer|min:1'])['id']; }
    private static function validate(array $input, array $rules): array
    {
        $v = Validator::make($input, $rules)->toIlluminate();
        if ($v->fails()) throw new BusinessException('请检查充值信息', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $v->errors()->toArray()]);
        return $v->validated();
    }
}
