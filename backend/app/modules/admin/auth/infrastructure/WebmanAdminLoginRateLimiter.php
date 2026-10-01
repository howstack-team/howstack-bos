<?php

declare(strict_types=1);

namespace app\modules\admin\auth\infrastructure;

use app\exception\BusinessException;
use app\modules\admin\auth\contract\AdminLoginRateLimiterInterface;
use app\shared\http\ApiErrorCode;
use support\limiter\Limiter;
use Throwable;
use Webman\Limiter\RateLimitException;

/**
 * 调用官方 Webman Limiter API，Redis 驱动由启动配置统一指定。
 */
final readonly class WebmanAdminLoginRateLimiter implements AdminLoginRateLimiterInterface
{
    /**
     * 初始化登录专属限流器；通过公开 check API 计数，不应用注解的 IP 白名单。
     * @param string $namespace 当前安装的计数命名空间
     */
    public function __construct(
        private string $namespace,
    ) {
    }

    /**
     * 计入一次登录尝试，使用驱动的固定时间窗口判断是否超限。
     * @param string $clientIp      统一解析后的客户端 IP
     * @param int    $limit         窗口内最多允许的尝试次数
     * @param int    $windowSeconds 固定窗口秒数
     * @return bool 是否允许继续认证
     * @throws BusinessException Redis 故障时保留异常链并拒绝登录
     */
    public function allows(string $clientIp, int $limit, int $windowSeconds): bool
    {
        try {
            Limiter::check($this->namespace . ':' . hash('sha256', $clientIp), $limit, $windowSeconds);
        } catch (RateLimitException) {
            return false;
        } catch (Throwable $exception) {
            throw new BusinessException(
                '登录安全服务暂不可用，请稍后重试',
                ApiErrorCode::SERVICE_UNAVAILABLE,
                503,
                previous: $exception,
            );
        }

        return true;
    }
}
