<?php

declare(strict_types=1);

namespace app\controller;

use support\Response;

/**
 * 提供系统官网默认首页。
 */
final class IndexController
{
    /**
     * 返回系统已安装后的默认首页。
     * @return Response 默认首页 HTML 响应
     */
    public function index(): Response
    {
        return response(<<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>皓量云擎云业务运营系统</title>
    <style>
        body { margin: 0; color: #1f1f1f; font-family: system-ui, sans-serif; background: #f5f7fa; }
        main { display: grid; min-height: 100vh; place-items: center; padding: 24px; box-sizing: border-box; }
        section { width: min(640px, 100%); padding: 48px; background: #fff; border-radius: 2px; box-shadow: 0 8px 32px rgb(0 0 0 / 8%); box-sizing: border-box; }
        h1 { margin: 0 0 16px; font-size: 28px; }
        p { margin: 0; color: #666; line-height: 1.8; }
    </style>
</head>
<body>
<main><section><h1>皓量云擎云业务运营系统</h1><p>系统已安装。官网主题与产品展示功能将在后续开发中接入。</p></section></main>
</body>
</html>
HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
