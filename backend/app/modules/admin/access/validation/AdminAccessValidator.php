<?php

declare(strict_types=1);

namespace app\modules\admin\access\validation;

use support\validation\Validator;

/**
 * 按管理动作验证并提取运营后台权限数据的输入白名单。
 */
final class AdminAccessValidator
{
    /**
     * 验证分页和查询参数。
     * @param array<string, mixed> $input 查询输入
     * @return array{keyword: string, page: int, page_size: int} 查询条件
     */
    public static function page(array $input): array
    {
        $validated = Validator::make([
            'keyword' => $input['keyword'] ?? '',
            'page' => $input['page'] ?? 1,
            'page_size' => $input['page_size'] ?? 20,
        ], [
            'keyword' => 'string|max:64',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|min:1|max:100',
        ])->validate();

        return ['keyword' => trim($validated['keyword']), 'page' => (int) $validated['page'], 'page_size' => (int) $validated['page_size']];
    }

    /**
     * 验证路径资源标识。
     * @param mixed $value 路径标识原始值
     * @return int 正整数资源标识
     */
    public static function id(mixed $value): int
    {
        $validated = Validator::make(['id' => $value], ['id' => 'required|integer|min:1'])->validate();

        return (int) $validated['id'];
    }

    /**
     * 验证管理员创建或编辑字段。
     * @param array<string, mixed> $input 账号表单原始输入
     * @param bool                 $create 是否为新增
     * @return array<string, mixed> 可写字段白名单
     */
    public static function administrator(array $input, bool $create): array
    {
        $data = array_intersect_key($input, array_flip(['username', 'nickname', 'employee_no', 'mobile', 'email', 'description', 'password', 'department_id', 'status', 'role_ids']));
        $rules = [
            'username' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{5,31}$/'],
            'nickname' => 'required|string|min:2|max:32',
            'employee_no' => 'sometimes|nullable|string|max:32',
            'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'email' => 'sometimes|nullable|email|max:254',
            'description' => 'sometimes|nullable|string|max:255',
            'password' => ($create ? 'required' : 'sometimes|nullable') . '|string|min:8|max:128',
            'department_id' => 'nullable|integer|min:1',
            'status' => 'required|integer|in:0,1',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'required|integer|min:1',
        ];

        return Validator::make($data, $rules)->validate();
    }

    /**
     * 验证角色创建或编辑字段。
     * @param array<string, mixed> $input 角色表单原始输入
     * @return array<string, mixed> 可写字段白名单
     */
    public static function role(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['name', 'code', 'sort_order', 'description', 'data_scope', 'status', 'permissions', 'department_ids']));

        return Validator::make($data, [
            'name' => 'required|string|min:2|max:64',
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            'description' => 'sometimes|nullable|string|max:255',
            'data_scope' => 'required|string|in:all,department_and_children,department,self,custom',
            'status' => 'required|integer|in:0,1',
            'permissions' => 'present|array',
            'permissions.*' => 'required|string|max:100',
            'department_ids' => 'sometimes|array',
            'department_ids.*' => 'required|integer|min:1',
        ])->validate();
    }

    /**
     * 验证部门节点字段。
     * @param array<string, mixed> $input 部门表单原始输入
     * @return array<string, mixed> 可写字段白名单
     */
    public static function department(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['name', 'sort_order', 'description', 'parent_id', 'status']));

        return Validator::make($data, [
            'name' => 'required|string|min:2|max:64',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            'description' => 'sometimes|nullable|string|max:255',
            'parent_id' => 'nullable|integer|min:1',
            'status' => 'required|integer|in:0,1',
        ])->validate();
    }
}
