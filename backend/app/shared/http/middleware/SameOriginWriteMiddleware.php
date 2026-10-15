<?php

declare(strict_types=1);

namespace app\shared\http\middleware;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 新增 Cookie 写入口要求同源；不接受缺失或跨站 Origin。 */
final class SameOriginWriteMiddleware implements MiddlewareInterface
{
    /** @param Request $request 当前请求 @param callable $handler 下游 @return Response 下游响应 */
    public function process(Request $request, callable $handler): Response
    {
        $origin = parse_url((string) $request->header('origin', ''));
        $host = parse_url('http://' . (string) $request->header('host', ''));
        if (!is_array($origin) || !is_array($host) || !in_array($origin['scheme'] ?? '', ['http', 'https'], true)
            || empty($origin['host']) || empty($host['host']) || isset($origin['user']) || isset($origin['query']) || isset($origin['fragment'])
            || strcasecmp($origin['host'], $host['host']) !== 0 || ($origin['port'] ?? null) !== ($host['port'] ?? null)) {
            throw new BusinessException('请求来源无效', ApiErrorCode::FORBIDDEN, 403);
        }
        return $handler($request);
    }
}
