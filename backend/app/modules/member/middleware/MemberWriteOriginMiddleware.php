<?php

declare(strict_types=1);

namespace app\modules\member\middleware;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** Cookie 认证的资金写请求必须来自当前站点 Origin。 */
final class MemberWriteOriginMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $origin = (string) $request->header('origin', '');
        $host = (string) $request->header('host', '');
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = parse_url('http://' . $host, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $requestPort = parse_url('http://' . $host, PHP_URL_PORT);
        $originScheme = parse_url($origin, PHP_URL_SCHEME);
        if ($originHost === false || $originHost === null || $requestHost === false
            || $requestHost === null || !in_array($originScheme, ['http', 'https'], true)
            || strcasecmp($originHost, $requestHost) !== 0 || $originPort !== $requestPort) {
            throw new BusinessException('请求来源无效', ApiErrorCode::FORBIDDEN, 403);
        }

        return $handler($request);
    }
}
