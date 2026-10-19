<?php

declare(strict_types=1);

namespace app\modules\support\middleware;

use app\exception\BusinessException;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\member\application\MemberAuthenticationContext;
use app\shared\http\ApiErrorCode;
use support\limiter\Limiter;
use Webman\Limiter\RateLimitException;
use Webman\MiddlewareInterface;
use Webman\Http\{Request, Response};

/** 按认证主体限制写入，不消耗查询或会话翻页额度。 */
final readonly class TicketWriteRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 注入工单用例依赖。
     * @param AdminAuthenticationContext $admins 显式注入的业务依赖
     * @param MemberAuthenticationContext $members 显式注入的业务依赖
     */
    public function __construct(private AdminAuthenticationContext $admins, private MemberAuthenticationContext $members) {}

    /**
     * 限制已认证主体的工单写入频率。
     * @param Request $request 当前已认证HTTP请求
     * @param callable $handler 下游HTTP处理器
     * @return Response 已校验结果或统一响应
     */
    public function process(Request $request, callable $handler): Response
    {
        if ($request->method() === 'POST') {
            $admin = str_starts_with($request->path(), '/api/admin/');
            $actor = $admin ? 'admin:' . $this->admins->account($request)->id : 'member:' . $this->members->account($request)->id;
            try {
                Limiter::check('ticket-write:' . $actor, 20, 60);
                if (!$admin && rtrim($request->path(), '/') === '/api/console/v1/tickets') Limiter::check('ticket-create:' . $actor, 5, 300);
            } catch (RateLimitException) {
                throw new BusinessException('工单提交过于频繁，请稍后再试', ApiErrorCode::TOO_MANY_REQUESTS, 429);
            } catch (\Throwable) {
                throw new BusinessException('工单提交保护暂不可用，请稍后重试', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
            }
        }
        return $handler($request);
    }
}
