<?php

declare(strict_types=1);

namespace app\modules\admin\operation\middleware;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\modules\admin\operation\application\RecordAdminOperationService;
use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 在管理员请求结束时记录已认证的写操作，不改变原业务响应。 */
final readonly class AdminOperationLogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminAuthenticationContext $context,
        private RecordAdminOperationService $operations,
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        if (!str_starts_with($path, '/api/admin/v1/')
            || str_starts_with($path, '/api/admin/v1/auth/')
            || str_starts_with($path, '/api/admin/v1/operation-logs')
            || !in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler($request);
        }

        $started = hrtime(true);
        $response = null;
        $failure = null;
        try {
            $response = $handler($request);
            return $response;
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                $actor = $this->context->account($request);
                $this->operations->record($request, $actor, $response, $failure, (int) ((hrtime(true) - $started) / 1_000_000));
            } catch (AdminAuthenticationException) {
                // 未认证请求由认证链处理，不写入管理员操作日志。
            } catch (Throwable $exception) {
                Log::channel('default')->error('管理员操作日志写入失败', ['exception' => $exception, 'path' => $path]);
            }
        }
    }
}
