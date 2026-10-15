<?php

declare(strict_types=1);

namespace tests\unit\modules\system_update\application;

use app\exception\Handler;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_update\application\GetSystemVersionService;
use app\modules\system_update\controller\SystemVersionController;
use app\shared\http\ApiResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use support\Container;
use support\Request;
use Throwable;

/** 版本信息只读查询的真实配置来源、授权和统一 HTTP 响应验证。 */
final class GetSystemVersionServiceTest extends TestCase
{
    public function testContainerReadsCanonicalCodeVersion(): void
    {
        $configuration = require base_path('config/version.php');
        $actor = new AdminAccount(1, 'admin', '管理员', 'hash', 1, null, true);

        self::assertSame(['current_version' => $configuration['current']],
            Container::get(GetSystemVersionService::class)->execute($actor));
    }

    /**
     * 校验查看权限足够，并且异常不泄漏实现细节或伪装版本。
     * @param string $scenario 请求场景
     * @param int    $status   HTTP 状态码
     * @param int    $code     稳定业务错误码
     */
    #[DataProvider('responseScenarios')]
    public function testResponseContract(string $scenario, int $status, int $code): void
    {
        $permissions = $scenario === 'forbidden' ? [] : ['admin.page.settings.view'];
        $repository = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([
            ['permissions' => $permissions, 'data_scope' => 'self', 'department_ids' => []],
        ]);
        $service = new GetSystemVersionService(new AdminAccessControlService($repository),
            $scenario === 'missing-version' ? '' : '2.3.4-rc.1');
        $context = new AdminAuthenticationContext();
        $responses = new ApiResponse();
        $request = new Request("GET /api/admin/v1/system/version HTTP/1.1\r\nHost: localhost\r\n\r\n");
        if ($scenario !== 'unauthenticated') {
            $context->set($request, new AdminAccount(1, 'reader', '只读管理员', 'hash', 1, null));
        }

        try {
            $response = (new SystemVersionController($service, $context, $responses))->show($request);
        } catch (Throwable $exception) {
            $response = (new Handler(new NullLogger(), false, $responses))->render($request, $exception);
        }
        $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, $payload['code']);
        self::assertSame(['code', 'message', 'data', 'request_id'], array_keys($payload));
        self::assertSame($payload['request_id'], $response->getHeader('X-Request-Id'));
        if ($scenario === 'success') {
            self::assertSame(['current_version' => '2.3.4-rc.1'], $payload['data']);
        } else {
            self::assertNull($payload['data']);
            self::assertStringNotContainsString('2.3.4', $response->rawBody());
            self::assertStringNotContainsString('系统代码版本未配置', $response->rawBody());
        }
    }

    /** @return iterable<string, array{string, int, int}> 版本查询及访问边界 */
    public static function responseScenarios(): iterable
    {
        yield 'read permission' => ['success', 200, 0];
        yield 'no login' => ['unauthenticated', 401, 40102];
        yield 'no page permission' => ['forbidden', 403, 40300];
        yield 'missing local version' => ['missing-version', 500, 50000];
    }
}
