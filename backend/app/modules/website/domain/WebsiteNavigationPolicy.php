<?php

declare(strict_types=1);

namespace app\modules\website\domain;

/** 首发期间不展示指向本站暂缓会员页面的导航，外站链接不受本站路径限制。 */
final class WebsiteNavigationPolicy
{
    /**
     * @param string $url 已通过官网链接格式校验的地址
     * @param string $siteUrl 本站公开根地址
     * @return bool 是否可展示
     */
    public function allows(string $url, string $siteUrl): bool
    {
        $target = parse_url($url);
        $site = parse_url($siteUrl);
        if (!is_array($target)) return false;
        if (isset($target['host']) && (!is_array($site)
            || strtolower($target['host']) !== strtolower($site['host'] ?? '')
            || ($target['port'] ?? (($target['scheme'] ?? '') === 'https' ? 443 : 80)) !== ($site['port'] ?? (($site['scheme'] ?? '') === 'https' ? 443 : 80)))) return true;
        $path = rtrim(rawurldecode($target['path'] ?? '/'), '/');
        foreach ([
            '/services/physical-servers', '/services/colocation', '/services/renewals',
            '/finance/refunds', '/finance/invoices', '/finance/coupons',
            '/account/security', '/account/verification', '/account/login-history',
        ] as $deferred) {
            $base = '/console' . $deferred;
            if ($path === $base || str_starts_with($path, $base . '/')) return false;
        }
        return true;
    }
}
