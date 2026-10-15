<?php

declare(strict_types=1);
namespace app\modules\website\validation;

/** 更新主题时保留原值，仅为新增对象字段补充声明的默认值。 */
final class ThemeConfigurationMerger
{
    public function merge(array $schema, mixed $defaults, mixed $stored): mixed
    {
        if ($schema['type'] !== 'object' || !is_array($stored)) return $stored;
        $result = $stored;
        foreach ($schema['properties'] as $key => $child) {
            if (!array_key_exists($key, $stored)) {
                if (is_array($defaults) && array_key_exists($key, $defaults)) $result[$key] = $defaults[$key];
            } else $result[$key] = $this->merge($child, $defaults[$key] ?? [], $stored[$key]);
        }
        return $result;
    }
}
