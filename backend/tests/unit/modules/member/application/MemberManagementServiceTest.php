<?php

declare(strict_types=1);

namespace tests\unit\modules\member\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\finance\application\FinanceMemberReferenceService;
use app\modules\finance\contract\FinanceRecordRepositoryInterface;
use app\modules\member\application\MemberManagementService;
use app\modules\member\contract\MemberRepositoryInterface;
use PHPUnit\Framework\TestCase;

/** 验证会员写入过程的凭证保护和唯一性约束。 */
final class MemberManagementServiceTest extends TestCase
{
    public function testCreateHashesPasswordAndNormalizesOptionalFields(): void
    {
        $members = $this->createMock(MemberRepositoryInterface::class);
        $members->method('mobileExists')->willReturn(false);
        $members->method('emailExists')->willReturn(false);
        $members->expects(self::once())->method('save')->with(null, self::callback(static function (array $data): bool {
            return $data['mobile'] === '13800138001'
                && $data['email'] === 'member@example.com'
                && !isset($data['password'])
                && password_verify('password123', $data['password_hash']);
        }))->willReturn(7);
        $members->expects(self::once())->method('find')->with(7)->willReturn(['id' => 7, 'mobile' => '13800138001']);

        $saved = $this->service($members)->save($this->founder(), null, [
            'nickname' => '测试会员', 'mobile' => ' 13800138001 ',
            'email' => ' MEMBER@EXAMPLE.COM ', 'description' => ' 说明 ',
            'password' => 'password123', 'status' => 1,
        ]);

        self::assertSame(7, $saved['id']);
    }

    public function testMultibyteOversizedPasswordIsRejectedBeforePersistence(): void
    {
        $members = $this->createMock(MemberRepositoryInterface::class);
        $members->expects(self::never())->method('save');
        $this->expectException(BusinessException::class);
        $this->service($members)->save($this->founder(), null, [
            'nickname' => '测试会员', 'mobile' => '13800138001', 'password' => str_repeat('密', 25), 'status' => 1,
        ]);
    }

    public function testDuplicateMobileIsRejectedBeforeWrite(): void
    {
        $members = $this->createMock(MemberRepositoryInterface::class);
        $members->method('mobileExists')->willReturn(true);
        $members->expects(self::never())->method('save');

        try {
            $this->service($members)->save($this->founder(), null, [
                'mobile' => '13800138001', 'nickname' => '测试会员', 'status' => 1, 'password' => 'password123',
            ]);
            self::fail('重复手机号应被拒绝');
        } catch (BusinessException $exception) {
            self::assertSame(409, $exception->httpStatus());
        }
    }

    public function testMemberWithOrdersCannotBeDeleted(): void
    {
        $members = $this->createMock(MemberRepositoryInterface::class);
        $members->method('find')->with(7)->willReturn(['id' => 7, 'mobile' => '13800138001']);
        $members->method('hasRealNameRecords')->with(7)->willReturn(false);
        $members->method('hasOrders')->with(7)->willReturn(true);
        $members->expects(self::never())->method('delete');

        try {
            $this->service($members)->delete($this->founder(), 7);
            self::fail('有订单的会员不能删除');
        } catch (BusinessException $exception) {
            self::assertSame(409, $exception->httpStatus());
        }
    }

    private function service(MemberRepositoryInterface $members): MemberManagementService
    {
        $accessRepository = $this->createStub(AdminAccessRepositoryInterface::class);

        $finance = $this->createStub(FinanceRecordRepositoryInterface::class);

        return new MemberManagementService($members, new AdminAccessControlService($accessRepository),
            new FinanceMemberReferenceService($finance));
    }

    private function founder(): AdminAccount
    {
        return new AdminAccount(1, 'admin01', '创始人', 'hash', 1, null, true);
    }
}
