<?php

declare(strict_types=1);

namespace app\modules\member\validation;

use support\validation\Validator;

/** 验证会员分组和等级管理输入。 */
final class MemberCatalogValidator
{
    /** @return array{keyword: string, status: int|null, page: int, page_size: int} */
    public static function page(array $input): array
    {
        return MemberValidator::page($input);
    }

    /** @return array<string, mixed> */
    public static function group(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['name', 'code', 'sort_order', 'description', 'status']));

        return Validator::make($data, [
            'name' => 'required|string|min:2|max:64',
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'sort_order' => 'required|integer|min:0|max:9999',
            'description' => 'sometimes|nullable|string|max:255',
            'status' => 'required|integer|in:0,1',
        ])->validate();
    }

    /** @return array<string, mixed> */
    public static function level(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['name', 'code', 'level_order', 'growth_threshold', 'description', 'status']));

        return Validator::make($data, [
            'name' => 'required|string|min:2|max:64',
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'level_order' => 'required|integer|min:0|max:9999',
            'growth_threshold' => 'required|integer|min:0|max:999999999999',
            'description' => 'sometimes|nullable|string|max:255',
            'status' => 'required|integer|in:0,1',
        ])->validate();
    }
}
