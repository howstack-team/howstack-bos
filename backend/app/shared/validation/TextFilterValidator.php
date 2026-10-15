<?php

declare(strict_types=1);

namespace app\shared\validation;

use support\validation\Validator;

/** 提取各列表声明的文本查询字段；SQL 列映射由所属模块维护。 */
final class TextFilterValidator
{
    /**
     * @param array<string, mixed> $input 请求查询参数
     * @param array<string, int> $fields 字段白名单及最大长度
     * @return array<string, string> 非空、已验证并去除首尾空白的条件
     */
    public static function validate(array $input, array $fields): array
    {
        $rules = [];
        foreach ($fields as $field => $length) {
            $rules[$field] = 'sometimes|nullable|string|max:' . $length;
        }

        $validated = Validator::make(array_intersect_key($input, $fields), $rules)->validate();
        $filters = [];
        foreach ($validated as $field => $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $filters[$field] = $text;
            }
        }

        return $filters;
    }
}
