<?php

declare(strict_types=1);

namespace app\modules\website\validation;

/** 官网公共资料独立于主题；平台品牌和备案仍引用系统设置。 */
final class WebsiteSettings
{
    /** @return array<string, mixed> 首次安装导航及图片默认值 */
    public static function defaults(): array
    {
        return ['logo_image_id' => '', 'favicon_image_id' => '', 'navigation' => [
            ['label' => '首页', 'target' => 'core', 'core_page' => 'home'],
            ['label' => '公司介绍', 'target' => 'theme_page', 'theme_id' => 'default', 'page_id' => 'company'],
            ['label' => '会员中心', 'target' => 'core', 'core_page' => 'console.home'],
        ], 'footer_links' => [], 'seo_title' => '', 'seo_keywords' => '', 'seo_description' => '',
            'company' => ['introduction' => '', 'contact_phone' => '', 'contact_email' => '', 'address' => '', 'image_id' => '']];
    }

    /** @return array<string, mixed> 可供前端渲染的声明 */
    public static function schema(): array
    {
        $text = static fn (int $length): array => ['type' => 'string', 'maxLength' => $length];
        $link = ['type' => 'object', 'additionalProperties' => false, 'required' => ['label', 'target'], 'properties' => [
            'label' => $text(40) + ['minLength' => 1], 'target' => $text(16) + ['enum' => ['core', 'theme_page', 'external']],
            'core_page' => $text(64), 'theme_id' => $text(64), 'page_id' => $text(64), 'url' => $text(500),
        ]];
        $properties = [
            'logo_image_id' => $text(32) + ['title' => '官网 Logo', 'format' => 'image-id', 'x-widget' => 'image'],
            'favicon_image_id' => $text(32) + ['title' => '网站图标', 'format' => 'image-id', 'x-widget' => 'image'],
            'navigation' => ['type' => 'array', 'title' => '顶部导航', 'maxItems' => 12, 'items' => $link],
            'footer_links' => ['type' => 'array', 'title' => '页脚链接', 'maxItems' => 12, 'items' => $link],
            // 已执行迁移使用此安装声明，旧字段仅保留在历史安装边界。
            'seo_title' => $text(120), 'seo_keywords' => $text(255), 'seo_description' => $text(500),
            'company' => ['type' => 'object', 'title' => '公司资料', 'additionalProperties' => false,
                'required' => ['introduction', 'contact_phone', 'contact_email', 'address', 'image_id'], 'properties' => [
                    'introduction' => $text(6000) + ['title' => '公司简介', 'x-widget' => 'textarea'],
                    'contact_phone' => $text(50) + ['title' => '联系电话'],
                    'contact_email' => $text(100) + ['title' => '联系邮箱'],
                    'address' => $text(300) + ['title' => '联系地址'],
                    'image_id' => $text(32) + ['title' => '公司图片', 'format' => 'image-id', 'x-widget' => 'image'],
                ]],
        ];
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    /** 当前运行时配置不再包含默认SEO，保留schema()供不可变历史迁移使用。 */
    public static function currentSchema(): array
    {
        $schema = self::schema();
        foreach (['seo_title', 'seo_keywords', 'seo_description'] as $field) unset($schema['properties'][$field]);
        $schema['required'] = array_keys($schema['properties']);
        return $schema;
    }

    /** 基础设置和导航分别提交，禁止跨页面写入其他字段。 */
    public static function section(string $section): array
    {
        $schema = self::currentSchema();
        $keys = match ($section) {
            'base' => ['logo_image_id', 'favicon_image_id', 'company'],
            'navigation' => ['navigation', 'footer_links'],
            default => throw new \InvalidArgumentException('未知官网配置分区'),
        };
        return ['type' => 'object', 'additionalProperties' => false, 'required' => $keys,
            'properties' => array_intersect_key($schema['properties'], array_flip($keys))];
    }
}
