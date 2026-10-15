<?php

declare(strict_types=1);

namespace app\modules\content\domain;

use HTMLPurifier;
use HTMLPurifier_Config;

/** 富文本写入前的白名单；客户端清理不能替代服务端清理。 */
final class ContentHtml
{
    public static function sanitize(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p[style],br,h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],strong,b,em,i,u,s,del,span[style],blockquote,ul,ol[start],li,pre,code,hr,a[href|title],img[src|alt|title],table,thead,tbody,tfoot,tr,th[colspan|rowspan],td[colspan|rowspan]');
        $config->set('CSS.AllowedProperties', ['text-align']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('HTML.TargetBlank', true);
        return trim((new HTMLPurifier($config))->purify($html));
    }

    /** 图片公告可不含文字；空段落和只含危险标签的正文不能保存。 */
    public static function hasContent(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/[^\s\x{00a0}\x{200b}]/u', $text) === 1 || preg_match('/<img\s[^>]*src=/i', $html) === 1;
    }
}
