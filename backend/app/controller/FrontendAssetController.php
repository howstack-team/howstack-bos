<?php

declare(strict_types=1);

namespace app\controller;

use support\Request;
use support\Response;

/** 在 Webman 内置服务器中提供 Vite 预构建资源。 */
final class FrontendAssetController
{
    public function show(Request $request, string $app, string $path): Response
    {
        if (!in_array($app, ['admin', 'console'], true)
            || preg_match('/^(?:assets\/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:js|css|png|svg|woff2?|webp)|favicon\.svg|icons\.svg)$/D', $path) !== 1) {
            return response('Not Found', 404);
        }
        $file = public_path('app/' . $app . '/' . $path);
        if (!is_file($file)) {
            return response('Not Found', 404);
        }
        $type = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };

        return response()->file($file)->withHeaders([
            'Content-Type' => $type,
            'Cache-Control' => str_starts_with($path, 'assets/') ? 'public, max-age=31536000, immutable' : 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
