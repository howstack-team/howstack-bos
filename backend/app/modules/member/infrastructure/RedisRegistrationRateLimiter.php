<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\exception\BusinessException;
use app\modules\member\contract\RegistrationRateLimiterInterface;
use app\shared\http\ApiErrorCode;
use support\limiter\Limiter;
use Webman\Limiter\RateLimitException;
use Throwable;

final class RedisRegistrationRateLimiter implements RegistrationRateLimiterInterface
{
    public function check(string $operation, string $target, string $ip): void
    {
        try {
            Limiter::check('hlyq-register:' . $operation . ':ip:' . hash('sha256', $ip), $operation === 'code' ? 10 : 30, 600);
            if ($operation === 'code') Limiter::check('hlyq-register:code:target:' . hash('sha256', $target), 1, 60);
        } catch (RateLimitException) {
            throw new BusinessException('操作过于频繁，请稍后重试', ApiErrorCode::TOO_MANY_REQUESTS, 429);
        } catch (Throwable) {
            throw new BusinessException('注册安全服务暂不可用，请稍后重试', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }
    }
}
