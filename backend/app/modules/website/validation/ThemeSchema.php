<?php

declare(strict_types=1);

namespace app\modules\website\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 主题设置的有限 JSON Schema 子集；不解析外部引用或执行主题代码。 */
final class ThemeSchema
{
    private const array KEYS = ['type', 'title', 'description', 'properties', 'required', 'additionalProperties', 'items', 'minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'enum', 'pattern', 'format', 'x-widget', 'default'];

    /** 校验声明本身，拒绝不支持的关键字而不是静默忽略。
     * @param array<string, mixed> $schema 设置声明
     */
    public function check(array $schema, int $depth = 0): void
    {
        if ($depth > 8 || array_diff(array_keys($schema), self::KEYS) !== []
            || !in_array($schema['type'] ?? null, ['object', 'array', 'string', 'integer', 'number', 'boolean'], true)) {
            self::fail('schema', '主题设置声明包含不支持的类型、关键字或过深结构');
        }
        foreach (['title' => 100, 'description' => 1000] as $key => $limit) {
            if (isset($schema[$key]) && (!is_string($schema[$key]) || mb_strlen($schema[$key]) > $limit)) self::fail('schema', '字段标题或说明无效');
        }
        foreach (['minItems', 'maxItems', 'minLength', 'maxLength'] as $key) {
            if (isset($schema[$key]) && (!is_int($schema[$key]) || $schema[$key] < 0)) self::fail('schema', '长度及数量边界必须是非负整数');
        }
        foreach (['minimum', 'maximum'] as $key) {
            if (isset($schema[$key]) && !is_int($schema[$key]) && !is_float($schema[$key])) self::fail('schema', '数值边界无效');
        }
        if (isset($schema['required']) && (!is_array($schema['required']) || !array_is_list($schema['required']) || count($schema['required']) !== count(array_unique($schema['required'], SORT_REGULAR)))) self::fail('schema', '必填声明必须是无重复的字段列表');
        if (isset($schema['enum']) && (!is_array($schema['enum']) || !array_is_list($schema['enum']) || count($schema['enum']) < 1 || count($schema['enum']) > 100)) self::fail('schema', '枚举声明无效');
        if (isset($schema['x-widget']) && !in_array($schema['x-widget'], ['input', 'textarea', 'image', 'color', 'select', 'switch', 'number', 'list'], true)) self::fail('schema', '不支持此字段展示类型');
        if ($schema['type'] === 'object') {
            if (($schema['additionalProperties'] ?? null) !== false || !is_array($schema['properties'] ?? null)) {
                self::fail('schema', '对象必须声明 properties 和 additionalProperties: false');
            }
            foreach ($schema['properties'] as $key => $child) {
                if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || !is_array($child)) self::fail('schema', '设置字段标识无效');
                $this->check($child, $depth + 1);
            }
            foreach ($schema['required'] ?? [] as $key) {
                if (!is_string($key) || !isset($schema['properties'][$key])) self::fail('schema', '必填字段未声明');
            }
        }
        if ($schema['type'] === 'array') {
            if (!is_array($schema['items'] ?? null) || !is_int($schema['maxItems'] ?? null) || $schema['maxItems'] > 100) self::fail('schema', '数组必须声明元素及最多100项的上限');
            $this->check($schema['items'], $depth + 1);
        }
        if ($schema['type'] === 'string' && (!is_int($schema['maxLength'] ?? null) || $schema['maxLength'] > 10000)) self::fail('schema', '文字设置必须声明不超过10000字符的上限');
        if (isset($schema['pattern']) && (!is_string($schema['pattern']) || strlen($schema['pattern']) > 200 || @preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~u', '') === false)) self::fail('schema', '正则声明无效');
        if (array_key_exists('default', $schema)) $this->validate($schema['default'], $schema, 'schema.default');
        if (isset($schema['format']) && !in_array($schema['format'], ['color', 'image-id'], true)) self::fail('schema', '不支持此字段格式');
    }

    /** 由声明生成初始配置，必填但没有默认值的字段会在完整校验时报错。 */
    public function defaults(array $schema): mixed
    {
        if (array_key_exists('default', $schema)) return $schema['default'];
        if ($schema['type'] !== 'object') return null;
        $value = [];
        foreach ($schema['properties'] as $key => $child) {
            if (array_key_exists('default', $child) || $child['type'] === 'object') $value[$key] = $this->defaults($child);
        }
        return $value;
    }

    /** 校验完整配置；未知字段、错误类型和超限内容直接报告字段错误。
     * @param mixed $value 用户配置
     * @param array<string, mixed> $schema 已确认的声明
     */
    public function validate(mixed $value, array $schema, string $path = 'settings'): void
    {
        $valid = match ($schema['type']) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value), 'integer' => is_int($value),
            'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value),
        };
        if (!$valid) self::fail($path, '字段类型不正确');
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) self::fail($path, '请选择有效选项');
        if ($schema['type'] === 'object') {
            foreach ($value as $key => $child) {
                if (!isset($schema['properties'][$key])) self::fail($path . '.' . $key, '未声明的设置字段');
                $this->validate($child, $schema['properties'][$key], $path . '.' . $key);
            }
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) self::fail($path . '.' . $key, '请填写此字段');
            }
        } elseif ($schema['type'] === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > $schema['maxItems']) self::fail($path, '项目数量超出允许范围');
            foreach ($value as $index => $child) $this->validate($child, $schema['items'], $path . '.' . $index);
        } elseif ($schema['type'] === 'string') {
            if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > $schema['maxLength']) self::fail($path, '文字长度超出允许范围');
            if (isset($schema['pattern']) && preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~u', $value) !== 1) self::fail($path, '格式不正确');
            if (($schema['format'] ?? '') === 'color' && !preg_match('/^#[a-fA-F0-9]{6}$/D', $value)) self::fail($path, '请使用六位十六进制颜色');
            if (($schema['format'] ?? '') === 'image-id' && $value !== '' && !preg_match('/^[a-f0-9]{32}$/D', $value)) self::fail($path, '图片标识无效');
        } elseif (is_numeric($value) && ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF))) {
            self::fail($path, '数值超出允许范围');
        }
    }

    /** 按声明保持JSON空对象为{}，避免PHP关联数组编码为[]导致跨端类型失真。 */
    public static function jsonValue(mixed $value, array $schema): mixed
    {
        if ($schema['type'] === 'object') {
            $result = [];
            foreach ($value as $key => $child) $result[$key] = self::jsonValue($child, $schema['properties'][$key]);
            return $result === [] ? new \stdClass() : $result;
        }
        if ($schema['type'] === 'array') return array_map(static fn (mixed $child): mixed => self::jsonValue($child, $schema['items']), $value);
        return $value;
    }

    /** 对外Schema中的properties始终为对象，required等列表仍为数组。 */
    public static function jsonSchema(array $schema): array
    {
        if ($schema['type'] === 'object') {
            $properties = [];
            foreach ($schema['properties'] as $key => $child) $properties[$key] = self::jsonSchema($child);
            $schema['properties'] = $properties === [] ? new \stdClass() : $properties;
        }
        if ($schema['type'] === 'array') $schema['items'] = self::jsonSchema($schema['items']);
        return $schema;
    }

    /** 统一字段错误，供配置和清单校验复用。
     * @return never
     */
    public static function fail(string $field, string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => [$message]]]);
    }
}
