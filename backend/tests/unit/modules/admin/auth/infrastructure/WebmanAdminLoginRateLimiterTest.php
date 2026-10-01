<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\auth\infrastructure;

use app\exception\BusinessException;
use app\exception\Handler;
use app\modules\admin\auth\infrastructure\WebmanAdminLoginRateLimiter;
use app\shared\http\ApiResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use support\limiter\Limiter;
use support\Request;
use Webman\Limiter\Driver\DriverInterface;

/**
 * 验证登录计数作用域和共享存储故障时的拒绝行为。
 */
final class WebmanAdminLoginRateLimiterTest extends TestCase
{
    private DriverInterface $originalDriver;

    /**
     * 保存官方驱动，测试不连接真实 Redis。
     * @return void
     */
    protected function setUp(): void
    {
        $property = new ReflectionProperty(Limiter::class, 'driver');
        if (!$property->isInitialized()) {
            Limiter::init(null);
        }
        $this->originalDriver = $property->getValue();
    }

    /**
     * 还原驱动，避免影响同进程内其他测试。
     * @return void
     */
    protected function tearDown(): void
    {
        $this->setDriver($this->originalDriver);
    }

    /**
     * 验证同 IP 共享次数，不同 IP、安装和窗口分别计数。
     * @return void
     */
    public function testIpAndInstallationIsolationWithSharedCounter(): void
    {
        $counts = [];
        $driver = $this->createStub(DriverInterface::class);
        $driver->method('increase')->willReturnCallback(
            static function (string $key, int $ttl) use (&$counts): int {
                $key .= ':' . $ttl;
                return $counts[$key] = ($counts[$key] ?? 0) + 1;
            },
        );
        $this->setDriver($driver);
        $firstWorker = new WebmanAdminLoginRateLimiter('installation-one');
        $secondWorker = new WebmanAdminLoginRateLimiter('installation-one');
        $otherInstall = new WebmanAdminLoginRateLimiter('installation-two');

        self::assertTrue($firstWorker->allows('203.0.113.1', 2, 60));
        self::assertTrue($secondWorker->allows('203.0.113.1', 2, 60));
        self::assertFalse($firstWorker->allows('203.0.113.1', 2, 60));
        self::assertTrue($secondWorker->allows('2001:db8::1', 2, 60));
        self::assertTrue($otherInstall->allows('203.0.113.1', 2, 60));
        self::assertTrue($firstWorker->allows('203.0.113.1', 2, 120));
        foreach (array_keys($counts) as $key) {
            self::assertStringNotContainsString('203.0.113.1', $key);
        }
    }

    /**
     * 验证 Redis 故障不会放行或泄漏连接信息，同时保留可记录的异常链。
     * @return void
     */
    public function testStorageFailureIsObservableAndReturnsSafe503(): void
    {
        $driver = $this->createStub(DriverInterface::class);
        $failure = new RuntimeException('redis failure credential=internal-test-value');
        $driver->method('increase')->willThrowException($failure);
        $this->setDriver($driver);
        $limiter = new WebmanAdminLoginRateLimiter('isolated-test');
        $log = new TestHandler();
        $handler = new Handler(new Logger('test', [$log]), false, new ApiResponse());
        $request = new Request("POST /api/admin/v1/auth/login HTTP/1.1\r\nHost: localhost\r\n\r\n");

        try {
            $limiter->allows('203.0.113.1', 10, 60);
            self::fail('存储故障时必须拒绝继续登录');
        } catch (BusinessException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            $handler->report($exception);
            $response = $handler->render($request, $exception);
            $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(503, $response->getStatusCode());
            self::assertSame(50300, $payload['code']);
            self::assertSame(['code', 'message', 'data', 'request_id'], array_keys($payload));
            self::assertSame($payload['request_id'], $response->getHeader('X-Request-Id'));
            self::assertStringNotContainsString('internal-test-value', $response->rawBody());
            self::assertTrue($log->hasErrorRecords());
        }
    }

    /**
     * 仅在测试中临时替换官方计数驱动，验证公共 API 的适配行为。
     * @param DriverInterface $driver 无外部存储副作用的驱动替身
     * @return void
     */
    private function setDriver(DriverInterface $driver): void
    {
        (new ReflectionProperty(Limiter::class, 'driver'))->setValue(null, $driver);
    }
}
