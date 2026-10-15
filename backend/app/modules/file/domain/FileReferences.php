<?php

declare(strict_types=1);
namespace app\modules\file\domain;

/** 提取宿主文件标识；远程链接不属于本机文件库。 */
final class FileReferences
{
    public static function collect(mixed $value): array
    {
        $ids = [];
        $includeIds = is_array($value);
        $walk = static function (mixed $item) use (&$walk, &$ids, $includeIds): void {
            if (is_array($item)) { foreach ($item as $child) $walk($child); return; }
            if (!is_string($item)) return;
            if ($includeIds && preg_match('/^[a-f0-9]{32}$/D', $item)) $ids[] = $item;
            preg_match_all('~/api/public/v1/(?:website/images|content/images|files)/([a-f0-9]{32})(?![a-f0-9])~', $item, $matches);
            array_push($ids, ...$matches[1]);
        };
        $walk($value);
        return array_values(array_unique($ids));
    }
}
