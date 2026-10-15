<?php

declare(strict_types=1);

namespace app\modules\finance\middleware;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use app\modules\member\application\MemberAuthenticationContext;
use support\limiter\Limiter;
use Webman\Limiter\RateLimitException;
use Webman\MiddlewareInterface;
use Webman\Http\{Request, Response};

final readonly class RechargeRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private MemberAuthenticationContext $context) {}
    public function process(Request $request, callable $handler): Response
    {
        try { Limiter::check('member-recharge-write:' . $this->context->account($request)->id, 30, 60); }
        catch (RateLimitException) { throw new BusinessException('操作频繁，请稍后再试', ApiErrorCode::TOO_MANY_REQUESTS, 429); }
        catch (\Throwable) { throw new BusinessException('支付安全服务暂不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503); }
        return $handler($request);
    }
}
