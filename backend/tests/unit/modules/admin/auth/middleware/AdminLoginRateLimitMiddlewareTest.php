<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\auth\middleware;

use app\modules\admin\auth\contract\AdminLoginRateLimiterInterface;
use app\modules\admin\auth\middleware\AdminLoginRateLimitMiddleware;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use app\shared\contract\ClientIpResolverInterface;
use app\shared\http\ApiResponse;
use PHPUnit\Framework\TestCase;
use support\Request;
use support\Response;

/**
 * 验证登录中间件实时取配置、统一取 IP，并在认证前拒绝超限请求。
 */
final class AdminLoginRateLimitMiddlewareTest extends TestCase
{
    /**
     * 验证常驻中间件不会缓存旧规则，且超限时返回完整 429 API 与等待时间。
     * @return void
     */
    public function testUpdatedRulesAreReadForEveryRequestAndExceededRequestIsRejected(): void
    {
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->expects(self::exactly(2))->method('values')->with('security')->willReturnOnConsecutiveCalls(
            ['security.session_expire' => '120', 'security.login_attempts' => '10', 'security.login_window' => '60'],
            ['security.session_expire' => '120', 'security.login_attempts' => '2', 'security.login_window' => '120'],
        );
        $ips = $this->createMock(ClientIpResolverInterface::class);
        $ips->expects(self::exactly(2))->method('resolve')->willReturn('203.0.113.5');
        $observed = [];
        $limiter = $this->createMock(AdminLoginRateLimiterInterface::class);
        $limiter->expects(self::exactly(2))->method('allows')->willReturnCallback(
            static function (string $ip, int $limit, int $window) use (&$observed): bool {
                $observed[] = [$ip, $limit, $window];
                return count($observed) === 1;
            },
        );
        $api = new ApiResponse();
        $middleware = new AdminLoginRateLimitMiddleware(new GetSystemSettingsService($settings), $ips, $limiter, $api);
        $called = 0;
        $handler = static function (Request $request) use (&$called, $api): Response {
            $called++;
            return $api->success($request, null);
        };
        $first = $middleware->process($this->request(), $handler);
        $limited = $middleware->process($this->request(), $handler);
        $payload = json_decode($limited->rawBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(1, $called);
        self::assertSame([['203.0.113.5', 10, 60], ['203.0.113.5', 2, 120]], $observed);
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame(42900, $payload['code']);
        self::assertSame(['code', 'message', 'data', 'request_id'], array_keys($payload));
        self::assertNull($payload['data']);
        self::assertSame($payload['request_id'], $limited->getHeader('X-Request-Id'));
        self::assertSame('120', $limited->getHeader('Retry-After'));
        self::assertSame('no-store', $limited->getHeader('Cache-Control'));
    }

    /**
     * 创建带伪造转发头的请求，由受信任代理解析服务决定最终 IP。
     * @return Request 登录请求
     */
    private function request(): Request
    {
        return new Request("POST /api/admin/v1/auth/login HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: 192.0.2.1\r\n\r\n");
    }
}
