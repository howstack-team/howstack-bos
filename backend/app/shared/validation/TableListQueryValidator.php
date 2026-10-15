<?php

declare(strict_types=1);

namespace app\shared\validation;

use support\validation\Validator;

/** 列表的文本、枚举和排序参数；字段及可选值由各模块显式声明。 */
final class TableListQueryValidator
{
    /**
     * @param array<string, mixed> $input 外部查询参数
     * @param array<string, int> $textFields 文本字段及最大长度
     * @param list<string> $sortFields 允许排序的业务字段
     * @param array<string, list<string>> $enumFields 单选枚举白名单
     * @return array<string, string> 已验证的非空条件和排序参数
     */
    public static function validate(array $input, array $textFields, array $sortFields, array $enumFields = []): array
    {
        $rules = [
            'sort_by' => 'sometimes|nullable|string|in:' . implode(',', $sortFields),
            'sort_order' => 'sometimes|nullable|string|in:asc,desc',
        ];
        foreach ($enumFields as $key => $values) {
            $rules[$key] = 'sometimes|nullable|string|in:' . implode(',', $values);
        }

        $values = Validator::make(array_intersect_key($input, $rules), $rules)->validate();
        $filters = $textFields === [] ? [] : TextFilterValidator::validate($input, $textFields);
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $filters[$key] = (string) $value;
            }
        }
        if (!isset($filters['sort_by'])) {
            unset($filters['sort_order']);
        } elseif (!isset($filters['sort_order'])) {
            $filters['sort_order'] = 'asc';
        }

        return $filters;
    }
}
