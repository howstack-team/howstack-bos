<?php

declare(strict_types=1);

namespace app\shared\plugin\http;

/** 通道 SDK 共用的 HTTPS URL 格式约束，不替代宿主域名与路由白名单。 */
final class HttpsUrl
{
    /**
     * @param string $url 待校验地址
     * @return bool 不含凭据、片段、反斜杠或控制字符的 HTTPS URL
     */
    public static function valid(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && !preg_match('/[\\\\\x00-\x20\x7f]/', $url);
    }
}
