<?php

declare(strict_types=1);

namespace app\modules\official_platform\domain;

/** 官网 v1 发布日志契约；只接受分类文本，不接受 HTML 或旧字符串结构。 */
final class ReleaseChangelog
{
    /** @return array{added:list<string>,improved:list<string>,fixed:list<string>,notices:list<string>} 无更新内容 */
    public static function empty(): array
    {
        return ['added' => [], 'improved' => [], 'fixed' => [], 'notices' => []];
    }

    /** @param mixed $value 发布日志或本机缓存中的日志 @return bool 是否符合 v1 分类及容量要求 */
    public static function isValid(mixed $value): bool
    {
        $sections = array_keys(self::empty());
        if (!is_array($value) || count($value) !== 4 || array_diff(array_keys($value), $sections) !== []) {
            return false;
        }
        foreach ($sections as $section) {
            $items = $value[$section];
            if (!is_array($items) || !array_is_list($items) || count($items) > 50) {
                return false;
            }
            foreach ($items as $item) {
                if (!is_string($item) || trim($item) === '' || !mb_check_encoding($item, 'UTF-8')
                    || mb_strlen($item, 'UTF-8') > 1000) {
                    return false;
                }
            }
        }

        return strlen(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) <= 32768;
    }
}
