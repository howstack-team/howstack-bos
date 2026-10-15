<?php

declare(strict_types=1);

namespace app\modules\member\validation;

use support\validation\Validator;

/** 验证并提取会员管理接口输入白名单。 */
final class MemberValidator
{
    /**
     * 验证会员列表查询条件。
     * @param array<string, mixed> $input 查询输入
     * @return array{keyword: string, status: int|null, page: int, page_size: int} 规范化查询条件
     */
    public static function page(array $input): array
    {
        $validated = Validator::make([
            'keyword' => $input['keyword'] ?? '',
            'status' => $input['status'] ?? null,
            'page' => $input['page'] ?? 1,
            'page_size' => $input['page_size'] ?? 10,
        ], [
            'keyword' => 'string|max:64',
            'status' => 'nullable|integer|in:0,1',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|min:1|max:100',
        ])->validate();

        return [
            'keyword' => trim($validated['keyword']),
            'status' => $validated['status'] === null ? null : (int) $validated['status'],
            'page' => (int) $validated['page'],
            'page_size' => (int) $validated['page_size'],
        ];
    }

    /**
     * 验证路径会员标识。
     * @param mixed $value 路径原始值
     * @return int 正整数会员标识
     */
    public static function id(mixed $value): int
    {
        $validated = Validator::make(['id' => $value], ['id' => 'required|integer|min:1'])->validate();

        return (int) $validated['id'];
    }

    /**
     * 验证创建或编辑会员字段。
     * @param array<string, mixed> $input  表单输入
     * @param bool                 $create 是否为创建
     * @return array<string, mixed> 可写字段白名单
     */
    public static function member(array $input, bool $create): array
    {
        $data = array_intersect_key($input, array_flip([
            'nickname', 'group_id', 'level_id', 'mobile', 'email', 'description', 'password', 'status',
        ]));

        return Validator::make($data, [
            'nickname' => 'required|string|min:2|max:64',
            'group_id' => 'sometimes|nullable|integer|min:1',
            'level_id' => 'sometimes|nullable|integer|min:1',
            'mobile' => ['nullable', 'required_without:email', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'email' => 'nullable|required_without:mobile|email|max:254',
            'description' => 'sometimes|nullable|string|max:255',
            'password' => ($create ? 'required' : 'sometimes|nullable') . '|string|min:8|max:72',
            'status' => 'required|integer|in:0,1',
        ])->validate();
    }
}
