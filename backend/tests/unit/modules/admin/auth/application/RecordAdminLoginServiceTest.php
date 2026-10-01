<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\auth\application;

use app\modules\admin\auth\application\RecordAdminLoginService;
use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\shared\contract\IpLocationResolverInterface;
use PHPUnit\Framework\TestCase;

final class RecordAdminLoginServiceTest extends TestCase
{
    public function testPersistsSuccessAndFailureSnapshots(): void
    {
        $repository = new class () implements LoginLogRepositoryInterface {
            public array $records = [];

            public function append(array $record): void { $this->records[] = $record; }
            public function paginate(string $keyword, ?int $status, int $page, int $pageSize): array { return ['data' => [], 'total' => 0]; }
            public function countBefore(string $cutoff): int { return 0; }
            public function deleteBatchBefore(string $cutoff, int $limit): int { return 0; }
        };
        $location = new class () implements IpLocationResolverInterface {
            public function resolve(string $ip): string { return '中国 广东省'; }
        };
        $service = new RecordAdminLoginService($repository, $location);
        $account = new AdminAccount(7, 'admin01', '张三', 'hash', 1, null);

        $service->record($account, 'admin01', true, '114.114.114.114', 'Mozilla/5.0 (Windows NT 10.0) Chrome/124.0');
        $service->record(null, 'unknown01', false, '2001:db8::1', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/125.0', 'invalid_credentials');

        self::assertSame(7, $repository->records[0]['admin_user_id']);
        self::assertSame('张三', $repository->records[0]['nickname']);
        self::assertSame(1, $repository->records[0]['status']);
        self::assertSame('中国 广东省', $repository->records[0]['client_ip_location']);
        self::assertSame('Chrome 124.0', $repository->records[0]['browser']);
        self::assertSame('Windows 10/11', $repository->records[0]['operating_system']);
        self::assertSame('unknown01', $repository->records[1]['nickname']);
        self::assertSame(0, $repository->records[1]['status']);
        self::assertSame('2001:db8::1', $repository->records[1]['client_ip']);
        self::assertSame('invalid_credentials', $repository->records[1]['failure_reason']);
    }
}
