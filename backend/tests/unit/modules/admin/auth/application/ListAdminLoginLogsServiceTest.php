<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\auth\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\application\ListAdminLoginLogsService;
use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use PHPUnit\Framework\TestCase;

final class ListAdminLoginLogsServiceTest extends TestCase
{
    public function testUnauthorizedUserCannotReadLogs(): void
    {
        $accessRepository = $this->createMock(AdminAccessRepositoryInterface::class);
        $accessRepository->expects(self::once())->method('grantsForAdmin')->willReturn([]);
        $logs = $this->createMock(LoginLogRepositoryInterface::class);
        $logs->expects(self::never())->method('paginate');
        $service = new ListAdminLoginLogsService(new AdminAccessControlService($accessRepository), $logs);
        $actor = new AdminAccount(7, 'ordinary', '普通用户', 'hash', 1, null);

        $this->expectException(BusinessException::class);
        $service->execute($actor, '', null, 1, 20);
    }

    public function testFounderCanReadPaginatedLogs(): void
    {
        $accessRepository = $this->createMock(AdminAccessRepositoryInterface::class);
        $accessRepository->expects(self::never())->method('grantsForAdmin');
        $logs = $this->createMock(LoginLogRepositoryInterface::class);
        $logs->expects(self::once())->method('paginate')->with('test', 0, 2, 20)
            ->willReturn(['data' => [], 'total' => 0]);
        $service = new ListAdminLoginLogsService(new AdminAccessControlService($accessRepository), $logs);
        $actor = new AdminAccount(1, 'founder', '创始用户', 'hash', 1, null, true);

        self::assertSame(['data' => [], 'total' => 0], $service->execute($actor, 'test', 0, 2, 20));
    }
}
