<?php

declare(strict_types=1);

namespace tests\unit\modules\finance\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\finance\application\ViewFinanceRecordsService;
use app\modules\finance\contract\FinanceRecordRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ViewFinanceRecordsServiceTest extends TestCase
{
    public function testUnauthorizedAdministratorCannotReadFinanceRecords(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([]);
        $records = $this->createMock(FinanceRecordRepositoryInterface::class);
        $records->expects(self::never())->method('paginate');
        $service = new ViewFinanceRecordsService(new AdminAccessControlService($access), $records);
        $actor = new AdminAccount(7, 'operator', '运营', 'hash', 1, null);

        $this->expectException(BusinessException::class);
        $service->paginate($actor, 'refunds', 1, 10, null);
    }

    public function testFounderCanReadWhitelistedResources(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $records = $this->createMock(FinanceRecordRepositoryInterface::class);
        $records->expects(self::once())->method('paginate')->with('payments', 2, 20, 'paid')
            ->willReturn(['data' => [], 'total' => 0]);
        $service = new ViewFinanceRecordsService(new AdminAccessControlService($access), $records);
        $founder = new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true);

        self::assertSame(0, $service->paginate($founder, 'payments', 2, 20, 'paid')['total']);
    }

    public function testUnknownResourceIsRejectedBeforeRepositoryAccess(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $records = $this->createMock(FinanceRecordRepositoryInterface::class);
        $records->expects(self::never())->method('find');
        $service = new ViewFinanceRecordsService(new AdminAccessControlService($access), $records);
        $founder = new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true);

        $this->expectException(BusinessException::class);
        $service->detail($founder, 'wallet_accounts', 1);
    }
}
