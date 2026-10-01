<?php

declare(strict_types=1);

namespace app\modules\admin\auth\middleware;

use app\exception\BusinessException;
use app\modules\admin\auth\contract\AdminLoginRateLimiterInterface;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\contract\ClientIpResolverInterface;
use app\shared\http\ApiErrorCode;
use app\shared\http\ApiResponse;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 对每次登录尝试应用实时安全设置；成功、失败和非法输入均计数。
 */
final readonly class AdminLoginRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 初始化登录频率中间件。
     * @param GetSystemSettingsService       $settings    实时系统设置
     * @param ClientIpResolverInterface      $clientIps   受信任代理 IP 解析器
     * @param AdminLoginRateLimiterInterface $rateLimiter 共享登录计数器
     * @param ApiResponse                    $apiResponse 统一 API 响应生成器
     */
    public function __construct(
        private GetSystemSettingsService $settings,
        private ClientIpResolverInterface $clientIps,
        private AdminLoginRateLimiterInterface $rateLimiter,
        private ApiResponse $apiResponse,
    ) {
    }

    /**
     * 在验证登录凭据前执行频率限制。
     * @param \support\Request $request 当前 HTTP 请求
     * @param callable         $handler 下游认证处理器
     * @return Response 下游响应或带 Retry-After 的统一 429 响应
     * @throws BusinessException 安全设置或计数存储不可用时抛出
     */
    public function process(Request $request, callable $handler): Response
    {
        $security = $this->settings->security();
        if (!$this->rateLimiter->allows(
            $this->clientIps->resolve($request),
            $security['login_attempts'],
            $security['login_window'],
        )) {
            return $this->apiResponse->error(
                $request,
                ApiErrorCode::TOO_MANY_REQUESTS,
                '登录尝试过于频繁，请稍后重试',
                429,
            )->withHeaders([
                // 使用完整窗口作保守等待上界，避免临界秒或多 Worker 时钟误差导致立即重试。
                'Retry-After' => (string) $security['login_window'],
                'Cache-Control' => 'no-store',
            ]);
        }

        return $handler($request);
    }
}
