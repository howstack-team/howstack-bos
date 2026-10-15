<?php

declare(strict_types=1);

namespace app\modules\member\middleware;

use app\exception\BusinessException;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\contract\ClientIpResolverInterface;
use app\shared\http\ApiErrorCode;
use app\shared\http\ApiResponse;
use support\limiter\Limiter;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Limiter\RateLimitException;
use Webman\MiddlewareInterface;

/** 会员登录使用独立于后台登录的 Redis 限流命名空间。 */
final readonly class MemberLoginRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private GetSystemSettingsService $settings,
        private ClientIpResolverInterface $clientIps,
        private ApiResponse $responses,
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        $security = $this->settings->security();
        try {
            Limiter::check('hlyq-member-login:' . hash('sha256', $this->clientIps->resolve($request)),
                $security['login_attempts'], $security['login_window']);
            $account = $request->post('account', $request->post('mobile'));
            if (is_string($account) && strlen($account) <= 254) {
                Limiter::check('hlyq-member-account-login:' . hash('sha256', strtolower(trim($account))),
                    $security['login_attempts'], $security['login_window']);
            }
        } catch (RateLimitException) {
            return $this->responses->error($request, ApiErrorCode::TOO_MANY_REQUESTS,
                '登录尝试过于频繁，请稍后重试', 429)->withHeaders(['Retry-After' => (string) $security['login_window']]);
        } catch (Throwable $exception) {
            throw new BusinessException('登录安全服务暂不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503,
                previous: $exception);
        }

        return $handler($request);
    }
}
