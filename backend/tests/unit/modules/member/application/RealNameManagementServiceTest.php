<?php

declare(strict_types=1);

namespace tests\unit\modules\member\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\member\application\RealNameManagementService;
use app\modules\member\contract\RealNameAuthenticationRepositoryInterface;
use PHPUnit\Framework\TestCase;

/** 验证实名认证拒绝原因和并发审核保护。 */
final class RealNameManagementServiceTest extends TestCase
{
    public function testRejectedReviewRequiresReason(): void
    {
        $records = $this->createMock(RealNameAuthenticationRepositoryInterface::class);
        $records->expects(self::never())->method('reviewPending');

        $this->expectException(BusinessException::class);
        $this->service($records)->review($this->founder(), 1, 'rejected', '');
    }

    public function testReviewUsesPendingConditionalUpdate(): void
    {
        $records = $this->createMock(RealNameAuthenticationRepositoryInterface::class);
        $records->expects(self::exactly(2))->method('find')->with(2)->willReturn(['id' => 2, 'member_id' => 3, 'status' => 'pending'], ['id' => 2, 'member_id' => 3, 'status' => 'approved']);
        $records->expects(self::once())->method('reviewPending')->with(2, 'approved', 1, '')->willReturn(true);

        $result = $this->service($records)->review($this->founder(), 2, 'approved', '不会保存');

        self::assertSame('approved', $result['status']);
    }

    private function service(RealNameAuthenticationRepositoryInterface $records): RealNameManagementService
    {
        $transactions = $this->createStub(\app\shared\contract\TransactionManagerInterface::class);
        $transactions->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        return new RealNameManagementService($records, new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)),
            $this->createStub(\app\modules\message\contract\MessagePublisherInterface::class), $transactions);
    }

    private function founder(): AdminAccount { return new AdminAccount(1, 'admin01', '创始人', 'hash', 1, null, true); }
}
