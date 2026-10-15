<?php

declare(strict_types=1);

namespace app\modules\channel\validation;

use app\exception\BusinessException;
use app\modules\channel\domain\ChannelCategories;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 路径、列表与声明式配置统一白名单验证，不接受客户端提供字段定义。 */
final class ChannelValidator
{
    /**
     * @param mixed $value 路径值
     * @return string 已支持类别
     */
    public function category(mixed $value): string
    {
        return $this->validate(['category' => $value], [
            'category' => 'required|string|in:' . implode(',', array_keys(ChannelCategories::LABELS)),
        ])['category'];
    }

    /**
     * @param mixed $value 路径值
     * @return int 正整数标识
     */
    public function id(mixed $value): int
    {
        return (int) $this->validate(['id' => $value], ['id' => 'required|integer|min:1'])['id'];
    }

    /**
     * @param array<string, mixed> $input 查询参数
     * @return array<string, mixed> 组合查询与分页
     */
    public function filters(array $input): array
    {
        return $this->validate($input + ['page' => 1, 'page_size' => 10], [
            'name' => 'sometimes|string|max:128',
            'plugin_id' => 'sometimes|string|max:64',
            'status' => 'sometimes|integer|in:0,1',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|in:10,20,50,100',
            'sort_by' => 'sometimes|string|in:sort_order,created_at,updated_at',
            'sort_order' => 'sometimes|string|in:asc,desc',
        ]);
    }

    /**
     * @param array<string, mixed> $input 请求体
     * @return array<string, mixed> 可保存资料
     */
    public function values(array $input): array
    {
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $input['description'] = is_string($input['description'] ?? null) ? trim($input['description']) : ($input['description'] ?? '');
        return $this->validate($input, [
            'name' => 'required|string|max:128',
            'plugin_id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/',
            'configuration' => 'present|array|max:16',
            'clear_secrets' => 'sometimes|array|max:16',
            'clear_secrets.*' => 'string|max:64',
            'status' => 'required|integer:strict|in:0,1',
            'is_default' => 'required|boolean:strict',
            'sort_order' => 'required|integer:strict|min:0|max:999999',
            'description' => 'present|string|max:500',
            'revision' => 'required|integer:strict|min:0',
        ]);
    }

    /**
     * @param array<string, mixed> $input 请求体
     * @return array{revision: int, status: int} 启停请求
     */
    public function status(array $input): array
    {
        return $this->validate($input, [
            'revision' => 'required|integer:strict|min:1',
            'status' => 'required|integer:strict|in:0,1',
        ]);
    }

    /**
     * @param array<string, mixed> $input 请求体
     * @return int 归档版本
     */
    public function revision(array $input): int
    {
        return $this->validate($input, ['revision' => 'required|integer:strict|min:1'])['revision'];
    }

    /**
     * 私密空值在调用前合并原值；未知字段和非法枚举不能被静默保存。
     * @param list<array<string, mixed>> $fields 宿主验证过的配置声明
     * @param array<string, mixed> $input 配置值
     * @return array<string, string|int|bool> 白名单配置
     */
    public function configuration(array $fields, array $input): array
    {
        if (array_diff(array_keys($input), array_column($fields, 'key')) !== []) {
            throw new BusinessException('配置包含服务平台未声明的字段', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $values = $rules = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $input[$key] ?? $field['default'] ?? ($field['type'] === 'boolean' ? false : '');
            if (!$field['required'] && $value === '' && in_array($field['type'], ['integer', 'select'], true)) {
                continue;
            }
            $values[$key] = is_string($value) && $field['type'] !== 'password' ? trim($value) : $value;
            if ($field['type'] === 'select' && !in_array($values[$key], array_column($field['options'], 'value'), true)) {
                throw new BusinessException('参数校验失败', ApiErrorCode::VALIDATION_FAILED, 422,
                    ['errors' => ['configuration.' . $key => ['请选择有效的选项']]]);
            }
            $rule = $field['required'] && $field['type'] !== 'boolean' ? 'required' : 'present';
            $rule .= match ($field['type']) {
                'integer' => '|integer:strict|min:' . $field['minimum'] . '|max:' . $field['maximum'],
                'boolean' => '|boolean:strict',
                'select' => '|string',
                default => '|string|max:' . $field['max_length'],
            };
            if ($field['type'] === 'url' && $values[$key] !== '') {
                $rule .= '|url:http,https';
            }
            $rules['configuration.' . $key] = $rule;
        }
        return $this->validate(['configuration' => $values], $rules)['configuration'] ?? [];
    }

    /**
     * @param array<string, mixed> $input 外部资料
     * @param array<string, string> $rules 固定规则
     * @return array<string, mixed> 已验证字段
     * @throws BusinessException 输入无效，错误按字段返回
     */
    private function validate(array $input, array $rules): array
    {
        $validator = Validator::make($input, $rules)->toIlluminate();
        if ($validator->fails()) {
            throw new BusinessException('参数校验失败', ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => $validator->errors()->toArray()]);
        }
        return $validator->validated();
    }
}
