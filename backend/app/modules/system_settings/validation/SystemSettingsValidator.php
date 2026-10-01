<?php

declare(strict_types=1);

namespace app\modules\system_settings\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 固定表单的输入白名单，与数据库分类或客户端字段定义无关。 */
final class SystemSettingsValidator
{
    /**
     * 验证分类路径。
     * @param mixed $category 外部路径参数
     * @return string 已支持分类
     */
    public function category(mixed $category): string
    {
        $validator = Validator::make(['category' => $category], [
            'category' => 'required|string|in:base,security',
        ])->toIlluminate();
        if ($validator->fails()) {
            throw new BusinessException('不支持的设置分类', ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => $validator->errors()->toArray()]);
        }

        return $validator->validated()['category'];
    }

    /**
     * 验证并规范化固定分类的完整表单。
     * @param string               $category 已验证分类
     * @param array<string, mixed> $input 请求体
     * @return array<string, string|int> 已验证的字段和值
     * @throws BusinessException 字段不符合规则或包含未知字段
     */
    public function values(string $category, array $input): array
    {
        $rules = $category === 'base' ? [
            'site_title' => 'required|string|min:2|max:120',
            'site_subtitle' => 'present|string|max:120',
            'site_keywords' => 'present|string|max:255',
            'site_description' => 'present|string|max:500',
            'site_url' => 'required|string|url:http,https|max:255',
            'copyright' => 'present|string|max:200',
            'icp_record' => 'present|string|max:100',
            'public_security_record' => 'present|string|max:100',
            'business_license' => 'present|string|max:100',
        ] : [
            'session_expire' => 'required|integer:strict|min:5|max:1440',
            'login_attempts' => 'required|integer:strict|min:1|max:100',
            'login_window' => 'required|integer:strict|min:10|max:3600',
        ];
        foreach ($input as $field => $value) {
            if (is_string($value)) {
                $input[$field] = trim($value);
            }
        }
        // 外层 array 白名单同时拒绝额外键；错误转换为固定字段名便于前端定位。
        $validator = Validator::make(['settings' => $input], [
            'settings' => 'required|array:' . implode(',', array_keys($rules)),
            ...array_combine(array_map(static fn (string $key): string => 'settings.' . $key, array_keys($rules)), array_values($rules)),
        ])->toIlluminate();
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $key => $messages) {
                $errors[preg_replace('/^settings\./', '', $key)] = $messages;
            }
            throw new BusinessException('参数校验失败', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $errors]);
        }
        $values = $validator->validated()['settings'];
        if ($category === 'security') {
            $values = array_map(static fn (mixed $value): int => (int) $value, $values);
        }

        return $values;
    }
}
