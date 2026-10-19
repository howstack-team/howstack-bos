<?php

declare(strict_types=1);

namespace app\middleware;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

/** 社区首发暂停新的高级受理；不拦截历史读取、通知、原任务查询或收尾。 */
final class CommunityReleaseMiddleware implements MiddlewareInterface
{
    /**
     * @param Request $request 当前请求
     * @param callable $handler 后续鉴权及业务处理
     * @return Response 原响应
     */
    public function process(Request $request, callable $handler): Response
    {
        $path = rtrim($request->path(), '/');
        $deferred = $request->method() === 'POST' && (
            in_array($path, [
                '/api/admin/v1/finance/invoices',
                '/api/admin/v1/themes/install',
                '/api/admin/v1/application-center/prepare', '/api/admin/v1/application-center/install',
                '/api/admin/v1/system-updates/check', '/api/admin/v1/system-updates/start',
                '/api/admin/v1/official-platform/license/activate',
                '/api/admin/v1/member-levels',
            ], true)
        );
        $deferred = $deferred || (
            in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)
            && preg_match('~^/api/admin/v1/member-levels/[0-9]+$~D', $path) === 1
        ) || ($request->method() === 'DELETE' && preg_match('~^/api/admin/v1/themes/[0-9]+$~D', $path) === 1);
        if ($deferred) {
            throw new BusinessException('当前版本暂未开放此操作', ApiErrorCode::NOT_FOUND, 404);
        }
        return $handler($request);
    }
}
