<?php

declare(strict_types=1);

namespace app\modules\service_instance\middleware;

use app\exception\BusinessException;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\member\application\MemberAuthenticationContext;
use app\shared\http\ApiErrorCode;
use support\limiter\Limiter;
use Webman\Limiter\RateLimitException;
use Webman\MiddlewareInterface;
use Webman\Http\{Request, Response};

/** 仅签发受限流约束；持续访问检查不消耗签发额度。 */
final readonly class InstanceVncRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param AdminAuthenticationContext $admins 后台身份
     * @param MemberAuthenticationContext $members 会员身份
     */
    public function __construct(private AdminAuthenticationContext $admins, private MemberAuthenticationContext $members)
    {
    }

    /**
     * @param Request $request 认证请求
     * @param callable $handler 后续处理器
     * @return Response 统一限流或正常结果
     */
    public function process(Request $request, callable $handler): Response
    {
        if ($request->method() === 'POST') {
            $actor = str_starts_with($request->path(), '/api/admin/')
                ? 'admin:' . $this->admins->account($request)->id : 'member:' . $this->members->account($request)->id;
            try {
                Limiter::check('service-vnc:' . $actor, 10, 60);
                Limiter::check('service-vnc-resource:' . $actor . ':' . hash('sha256', $request->path()), 3, 30);
            } catch (RateLimitException) {
                throw new BusinessException('VNC连接申请过于频繁，请稍后再试', ApiErrorCode::TOO_MANY_REQUESTS, 429);
            } catch (\Throwable) {
                throw new BusinessException('VNC访问保护暂不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
            }
        }
        return $handler($request);
    }
}
