<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\infrastructure;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/** 升级期间暂停业务，登录及维护状态读取仍可访问。 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly \app\modules\install\contract\InstallationStateRepositoryInterface $state)
    {
    }
    /**
     * @param Request $request 请求
     * @param callable $handler 后续处理
     * @return Response 维护提示或原响应
     */
    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        $marker = (string) config('official_platform.private_directory') . '/maintenance.json';
        $admin = '/' . $this->state->adminPath();
        if (!is_file($marker) || preg_match('~^/api/admin/v1/(auth/|system-updates$|official-platform/(connection|license)$)~D', $path)
            || (in_array($request->method(), ['GET', 'HEAD'], true) && ($path === $admin || str_starts_with($path, $admin . '/')))) {
            return $handler($request);
        }
        if (str_starts_with($path, '/api/')) {
            return new Response(503, ['Content-Type' => 'application/json', 'Retry-After' => '30'],
                json_encode(['code' => 50300, 'message' => '系统正在更新，请稍后重试', 'data' => null, 'request_id' => $request->header('x-request-id') ?: bin2hex(random_bytes(16))], JSON_UNESCAPED_UNICODE));
        }
        return new Response(503, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '30'], '系统正在更新，请稍后重试');
    }
}
