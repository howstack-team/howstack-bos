<?php

declare(strict_types=1);

namespace app\modules\channel\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 与通道 API 使用一致的白名单验证及结构化字段错误。 */
final class LocalObjectDownloadValidator
{
    /**
     * @param array<string, mixed> $input 原始查询
     * @return array{namespace: string, key: string, expires: int|string, signature: string} 已验证参数
     */
    public function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'namespace' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]{0,63}$/D'],
            'key' => ['required', 'string', 'max:512'],
            'expires' => ['required', 'integer', 'min:1'],
            'signature' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        if ($validator->fails()) {
            throw new BusinessException('参数校验失败', ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => $validator->errors()->toArray()]);
        }
        return $validator->validated();
    }
}
