<?php

declare(strict_types=1);

namespace app\modules\admin\auth\domain;

/** 将 User-Agent 归纳为适合审计列表展示的浏览器和操作系统。 */
final class UserAgentSummary
{
    /** @return array{browser: string, operating_system: string} */
    public static function parse(string $userAgent): array
    {
        $agent = mb_substr($userAgent, 0, 512);
        $browser = '未知';
        foreach ([
            'Edge' => '/Edg(?:e|A|iOS)?\/([\d.]+)/i',
            'Opera' => '/OPR\/([\d.]+)/i',
            'Firefox' => '/(?:Firefox|FxiOS)\/([\d.]+)/i',
            'Chrome' => '/(?:Chrome|CriOS)\/([\d.]+)/i',
            'Safari' => '/Version\/([\d.]+).*Safari/i',
        ] as $name => $pattern) {
            if (preg_match($pattern, $agent, $matches) === 1) {
                $browser = $name . ' ' . $matches[1];
                break;
            }
        }

        $os = '未知';
        if (preg_match('/Windows NT ([\d.]+)/i', $agent, $matches) === 1) {
            $os = match ($matches[1]) {
                '10.0' => 'Windows 10/11',
                '6.3' => 'Windows 8.1',
                '6.1' => 'Windows 7',
                default => 'Windows NT ' . $matches[1],
            };
        } elseif (preg_match('/Android ([\d.]+)/i', $agent, $matches) === 1) {
            $os = 'Android ' . $matches[1];
        } elseif (preg_match('/(?:iPhone|iPad).*OS ([\d_]+)/i', $agent, $matches) === 1) {
            $os = 'iOS ' . str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Mac OS X ([\d_.]+)/i', $agent, $matches) === 1) {
            $os = 'macOS ' . str_replace('_', '.', $matches[1]);
        } elseif (stripos($agent, 'Linux') !== false) {
            $os = 'Linux';
        }

        return ['browser' => $browser, 'operating_system' => $os];
    }
}
