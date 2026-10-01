<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\operation\application;

use app\exception\BusinessException;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\operation\application\OperationLogPayloadSanitizer;
use app\modules\admin\operation\application\RecordAdminOperationService;
use app\modules\admin\operation\contract\OperationLogRepositoryInterface;
use app\modules\admin\operation\middleware\AdminOperationLogMiddleware;
use app\shared\contract\ClientIpResolverInterface;
use app\shared\contract\IpLocationResolverInterface;
use app\shared\http\ApiErrorCode;
use app\shared\http\ApiResponse;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

/** 核对操作日志写入与敏感信息入库边界。 */
final class RecordAdminOperationServiceTest extends TestCase
{
    public function testSuccessfulOperationStoresSummaryAndAllowlistedParameters(): void
    {
        $logs = new MemoryOperationLogRepository();
        $body = '{"username":"admin_user","password":"secret-123","nickname":"张三","status":1,"role_ids":[2]}';
        $request = new Request("POST /api/admin/v1/administrators?keyword=alice&status=1 HTTP/1.1\r\nHost: example.test\r\nContent-Type: application/json\r\nAuthorization: Bearer private-token\r\nUser-Agent: Chrome/151.0.0.0\r\nX-Request-Id: test-request-1234\r\n\r\n" . $body);
        $response = new Response(200, ['Content-Type' => 'application/json', 'Set-Cookie' => 'private-cookie'],
            '{"code":0,"message":"ok","data":{"id":3,"password_hash":"private-hash"}}');

        $this->service($logs)->record($request, $this->account(), $response, null, 24);

        self::assertCount(1, $logs->records);
        $record = $logs->records[0];
        self::assertSame('新增系统用户', $record['operation']);
        self::assertSame('/api/admin/v1/administrators', $record['request_url']);
        self::assertSame(1, $record['status']);
        self::assertSame(24, $record['duration_ms']);
        self::assertSame('本机', $record['client_ip_location']);
        self::assertSame('test-request-1234', $record['request_id']);
        self::assertSame([
            'username' => 'admin_user', 'nickname' => '张三', 'status' => 1, 'role_ids' => [2],
        ], json_decode($record['request_params'], true));
        foreach (['request_headers', 'request_body', 'response_headers', 'response_body'] as $field) {
            self::assertArrayNotHasKey($field, $record);
        }
        self::assertStringNotContainsString('secret-123', $record['request_params']);
    }

    public function testFailedOperationStoresStatusAndTargetId(): void
    {
        $logs = new MemoryOperationLogRepository();
        $request = new Request("DELETE /api/admin/v1/roles/7 HTTP/1.1\r\nHost: example.test\r\n\r\n");
        $failure = new BusinessException('无权执行此操作', ApiErrorCode::FORBIDDEN, 403);

        $this->service($logs)->record($request, $this->account(), null, $failure, 8);

        self::assertSame(0, $logs->records[0]['status']);
        self::assertSame(403, $logs->records[0]['http_status']);
        self::assertSame(['target_id' => 7], json_decode($logs->records[0]['request_params'], true));
    }

    public function testOnlyKnownBusinessFieldsAreKept(): void
    {
        $sanitizer = new OperationLogPayloadSanitizer();

        self::assertSame([
            'target_id' => 5, 'name' => '测试角色', 'code' => 'test', 'status' => 1,
        ], json_decode($sanitizer->params('roles', '5', [
            'name' => '测试角色', 'code' => 'test', 'status' => 1,
            'description' => '包含个人信息', 'password' => 'secret-123', 'api_key' => 'private-token',
        ]), true));
        self::assertSame(['target_id' => 5], json_decode($sanitizer->params('unknown', '5', [
            'name' => '不可推断的字段',
        ]), true));
        self::assertSame('257 项，超过审计明细上限', json_decode($sanitizer->params('roles', null, [
            'permissions' => array_fill(0, 257, 'admin.roles.view'),
        ]), true)['permissions']);
    }

    public function testMiddlewareRecordsOnlyAuthenticatedWriteRequests(): void
    {
        $logs = new MemoryOperationLogRepository();
        $context = new AdminAuthenticationContext();
        $middleware = new AdminOperationLogMiddleware($context, $this->service($logs));
        $request = new Request("POST /api/admin/v1/roles HTTP/1.1\r\nHost: example.test\r\n\r\n");

        $response = $middleware->process($request, function (Request $current) use ($context): Response {
            $context->set($current, $this->account());
            return new Response(201, ['Content-Type' => 'application/json'], '{"code":0}');
        });

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $logs->records);
        self::assertSame('新增角色权限', $logs->records[0]['operation']);

        $anonymous = new Request("POST /api/admin/v1/roles HTTP/1.1\r\nHost: example.test\r\n\r\n");
        $middleware->process($anonymous, static fn (): Response => new Response(401));
        self::assertCount(1, $logs->records);
    }

    private function service(MemoryOperationLogRepository $logs): RecordAdminOperationService
    {
        return new RecordAdminOperationService(
            $logs,
            new class implements ClientIpResolverInterface {
                public function resolve(Request $request): string { return '127.0.0.1'; }
            },
            new class implements IpLocationResolverInterface {
                public function resolve(string $ip): string { return '本机'; }
            },
            new ApiResponse(),
            new OperationLogPayloadSanitizer(),
        );
    }

    private function account(): AdminAccount
    {
        return new AdminAccount(1, 'admin_user', '管理员', 'unused-hash', 1, null);
    }
}

/** 不访问数据库的操作日志测试仓储。 */
final class MemoryOperationLogRepository implements OperationLogRepositoryInterface
{
    /** @var list<array<string, int|string|null>> */
    public array $records = [];

    public function append(array $record): void { $this->records[] = $record; }
    public function paginate(string $keyword, ?int $status, int $page, int $pageSize): array { return ['data' => [], 'total' => 0]; }
    public function find(int $id): ?array { return null; }
    public function countBefore(string $cutoff): int { return 0; }
    public function deleteBatchBefore(string $cutoff, int $limit): int { return 0; }
}
