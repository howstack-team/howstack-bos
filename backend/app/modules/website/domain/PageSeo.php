<?php

declare(strict_types=1);

namespace app\modules\website\domain;

/** 页面身份与逐字段继承规则；后台列表和公开渲染使用同一个解析器。 */
final class PageSeo
{
    /** @return array{title: string, description: string, keywords: string, indexable: ?bool} 留空及NULL表示继承 */
    public static function emptySettings(): array
    {
        return ['title' => '', 'description' => '', 'keywords' => '', 'indexable' => null];
    }

    public static function scope(string $themeId, string $pageId): string
    {
        return in_array($pageId, ['home', 'products'], true) ? 'core' : 'theme:' . $themeId;
    }

    /** @param list<array<string, mixed>> $rows 页面配置记录 */
    public static function settings(array $rows, string $scope, string $pageId): array
    {
        foreach ($rows as $row) {
            if ($row['scope'] === $scope && $row['page_id'] === $pageId) return array_intersect_key($row, self::emptySettings());
        }
        return self::emptySettings();
    }

    /** @return array{effective: array{title: string, description: string, keywords: string, indexable: bool}, sources: array<string, string>} */
    public static function resolve(string $id, array $declaration, string $siteName, array $settings): array
    {
        $home = $id === 'home';
        $defaults = [
            'title' => $home ? '' : $declaration['title'],
            'description' => $declaration['seo']['description'] ?? '',
            'keywords' => $declaration['seo']['keywords'] ?? '',
            'indexable' => $declaration['seo']['indexable'] ?? true,
        ];
        $sources = ['title' => $home ? 'default' : 'page_default',
            'description' => ($declaration['seo']['description'] ?? '') !== '' ? 'page_default' : 'default',
            'keywords' => ($declaration['seo']['keywords'] ?? '') !== '' ? 'page_default' : 'default', 'indexable' => 'page_default'];
        foreach (['title', 'description', 'keywords', 'indexable'] as $field) {
            $value = $settings[$field];
            if ($field === 'indexable' ? $value !== null : trim($value) !== '') {
                $defaults[$field] = $value;
                $sources[$field] = 'override';
            }
        }
        $suffix = ' - ' . $siteName;
        if ($home) {
            $subtitle = trim($defaults['title']);
            $defaults['title'] = $subtitle === '' || $subtitle === $siteName ? $siteName
                : (str_starts_with($subtitle, $siteName . ' - ') ? $subtitle : $siteName . ' - ' . $subtitle);
        } elseif ($defaults['title'] !== $siteName && !str_ends_with($defaults['title'], $suffix)) {
            $defaults['title'] .= $suffix;
        }
        return ['effective' => $defaults, 'sources' => $sources];
    }
}
