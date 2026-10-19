<?php

declare(strict_types=1);

namespace app\modules\finance\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 仅接受分页，不接受可替换会员/账户身份或任意排序的参数。 */
final class MemberWalletEntriesValidator
{
    /**
     * @param array<string,mixed> $input HTTP 查询参数
     * @return array{page:int,page_size:int} 有界分页
     * @throws BusinessException 参数未知、类型或范围无效
     */
    public static function page(array $input): array
    {
        $unknown = array_diff(array_keys($input), ['page', 'page_size']);
        if ($unknown !== []) {
            $errors = [];
            foreach ($unknown as $field) $errors[(string) $field] = ['不支持此查询参数'];
            throw new BusinessException('请检查分页参数', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $errors]);
        }
        $validator = Validator::make(['page' => $input['page'] ?? 1, 'page_size' => $input['page_size'] ?? 20], [
            'page' => 'required|integer|min:1|max:100000', 'page_size' => 'required|integer|in:10,20,50,100',
        ])->toIlluminate();
        if ($validator->fails()) throw new BusinessException('请检查分页参数', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $validator->errors()->toArray()]);
        $data = $validator->validated();
        return ['page' => (int) $data['page'], 'page_size' => (int) $data['page_size']];
    }
}
