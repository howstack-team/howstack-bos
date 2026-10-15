<?php

declare(strict_types=1);

namespace tests\unit\modules\business_analysis;

use app\exception\BusinessException;
use app\exception\Handler;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\access\domain\PermissionCatalog;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\business_analysis\application\GetBusinessAnalysisService;
use app\modules\business_analysis\contract\BusinessAnalysisRepositoryInterface;
use app\modules\business_analysis\controller\AdminBusinessAnalysisController;
use app\modules\business_analysis\validation\AnalysisQueryValidator;
use app\shared\http\ApiResponse;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use support\{Request, Response};

/** 验证权限边界、日期校验和统一接口响应，不依赖安装数据库。 */
final class BusinessAnalysisApiTest extends TestCase
{
    public function testDefaultRangeAndPreviousPeriodCrossYearAndLeapDay(): void
    {
        $now = new DateTimeImmutable('2026-01-09 12:00:00', new DateTimeZone('Asia/Shanghai'));
        $period = AnalysisQueryValidator::validate([], $now);
        self::assertSame('2025-12-11', $period->metadata()['start_date']);
        self::assertSame('2025-11-11', $period->metadata()['previous_start_date']);
        self::assertSame('2025-12-10', $period->metadata()['previous_end_date']);
        self::assertSame(30, $period->days());
        $leap = AnalysisQueryValidator::validate(['start_date' => '2024-02-28', 'end_date' => '2024-03-01'], $now);
        self::assertSame(3, $leap->days());
        $ending = AnalysisQueryValidator::validate(['end_date' => '2025-10-31'], $now);
        self::assertSame('2025-10-02', $ending->metadata()['start_date']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidDates(): iterable
    {
        yield '非日历日期' => [['start_date' => '2026-02-30'], 'start_date'];
        yield '非法格式' => [['start_date' => '2026/10/01'], 'start_date'];
        yield '日期倒序' => [['start_date' => '2026-10-02', 'end_date' => '2026-10-01'], 'end_date'];
        yield '跨度超限' => [['start_date' => '2025-01-01', 'end_date' => '2026-10-09'], 'start_date'];
        yield '结束日未来' => [['end_date' => '2026-10-10'], 'end_date'];
        yield '数组入参' => [['start_date' => ['2026-10-01']], 'start_date'];
        yield '空日期' => [['end_date' => ''], 'end_date'];
        yield '未开放粒度' => [['granularity' => 'hour'], 'granularity'];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidQueryReturnsFieldErrors(array $input, string $field): void
    {
        try {
            AnalysisQueryValidator::validate($input, new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('Asia/Shanghai')));
            self::fail('非法参数必须被拒绝');
        } catch (BusinessException $error) {
            $response = (new Handler(new NullLogger(), true, new ApiResponse()))->render($this->request(), $error);
            $body = $this->body($response);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(42200, $body['code']);
            self::assertArrayHasKey($field, $body['data']['errors']);
            self::assertSame($response->getHeader('X-Request-Id'), $body['request_id']);
        }
    }

    public function testDashboardPermissionDoesNotPermitAnalysisOrReadRepository(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([['permissions' => ['admin.page.dashboard.view']]]);
        $repository = $this->createMock(BusinessAnalysisRepositoryInterface::class);
        $repository->expects(self::never())->method('aggregate');
        $controller = $this->controller($repository, $grants);
        $request = $this->request();
        (new AdminAuthenticationContext())->set($request, new AdminAccount(2, 'operator', '运营', 'hash', 1, null));
        try {
            $controller->show($request);
            self::fail('工作台权限不能查看全站经营数据');
        } catch (BusinessException $error) {
            $response = (new Handler(new NullLogger(), true, new ApiResponse()))->render($request, $error);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame(40300, $this->body($response)['code']);
        }
        self::assertSame('总览', PermissionCatalog::all()['admin.page.analysis.view']['group']);
        self::assertArrayNotHasKey('admin.page.analysis.create', PermissionCatalog::all());
        self::assertArrayNotHasKey('admin.page.analysis.update', PermissionCatalog::all());
    }

    public function testAuthorizedRoleCanReadSuccessEnvelopeAndRequestId(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([['permissions' => ['admin.page.analysis.view']]]);
        $repository = $this->createStub(BusinessAnalysisRepositoryInterface::class);
        $repository->method('aggregate')->willReturn(['created' => [], 'paid' => [], 'refunds' => [], 'members' => [],
            'paid_members' => ['current' => 0, 'previous' => 0]]);
        $request = $this->request();
        (new AdminAuthenticationContext())->set($request, new AdminAccount(2, 'operator', '运营', 'hash', 1, null));
        $response = $this->controller($repository, $grants)->show($request);
        $body = $this->body($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $body['code']);
        self::assertSame(['code', 'message', 'data', 'request_id'], array_keys($body));
        self::assertSame($response->getHeader('X-Request-Id'), $body['request_id']);
        self::assertSame('no-store, private', $response->getHeader('Cache-Control'));
        self::assertCount(30, $body['data']['trend']);
    }

    public function testUnexpectedDatabaseFailureDoesNotLeakSqlOrPaths(): void
    {
        $repository = $this->createStub(BusinessAnalysisRepositoryInterface::class);
        $repository->method('aggregate')->willThrowException(new RuntimeException('private SQL /private/path'));
        $request = $this->request();
        (new AdminAuthenticationContext())->set($request, new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true));
        try {
            $this->controller($repository, $this->createStub(AdminAccessRepositoryInterface::class))->show($request);
            self::fail('底层故障不能当作零数据返回');
        } catch (RuntimeException $error) {
            $response = (new Handler(new NullLogger(), true, new ApiResponse()))->render($request, $error);
            self::assertSame(500, $response->getStatusCode());
            self::assertSame(50000, $this->body($response)['code']);
            self::assertStringNotContainsString('private', $response->rawBody());
        }
    }

    private function controller(BusinessAnalysisRepositoryInterface $repository, AdminAccessRepositoryInterface $grants): AdminBusinessAnalysisController
    {
        return new AdminBusinessAnalysisController(new GetBusinessAnalysisService(new AdminAccessControlService($grants), $repository),
            new AdminAuthenticationContext(), new ApiResponse());
    }

    private function request(): Request
    {
        return new Request("GET /api/admin/v1/business-analysis HTTP/1.1\r\nHost: example.test\r\nX-Request-Id: analysis-test-123\r\n\r\n");
    }

    /** @return array<string, mixed> 统一响应 */
    private function body(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
