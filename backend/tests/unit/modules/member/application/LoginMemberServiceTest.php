<?php

declare(strict_types=1);

namespace tests\unit\modules\member\application;

use app\exception\BusinessException;
use app\modules\member\application\LoginMemberService;
use app\modules\member\contract\MemberAuthRepositoryInterface;
use app\modules\member\contract\MemberTokenServiceInterface;
use app\modules\member\domain\MemberAccount;
use PHPUnit\Framework\TestCase;

final class LoginMemberServiceTest extends TestCase
{
    public function testSuccessfulLoginReturnsProfileWithoutPassword(): void
    {
        $account = new MemberAccount(3, '13800138003', '客户', password_hash('secret123', PASSWORD_DEFAULT), 1, '72846193');
        $members = $this->createMock(MemberAuthRepositoryInterface::class);
        $members->expects(self::once())->method('findByMobile')->with('13800138003')->willReturn($account);
        $members->expects(self::once())->method('recordLogin')->with(3);
        $tokens = $this->createMock(MemberTokenServiceInterface::class);
        $tokens->expects(self::once())->method('issue')->with($account)->willReturn('token');

        $result = (new LoginMemberService($members, $tokens))->login('13800138003', 'secret123');
        self::assertSame(['id' => '72846193', 'mobile' => '13800138003', 'email' => null, 'nickname' => '客户'], $result['user']);
    }

    public function testDisabledMemberCannotLogin(): void
    {
        $account = new MemberAccount(3, '13800138003', '客户', password_hash('secret123', PASSWORD_DEFAULT), 0, '72846193');
        $members = $this->createStub(MemberAuthRepositoryInterface::class);
        $members->method('findByMobile')->willReturn($account);
        $tokens = $this->createMock(MemberTokenServiceInterface::class);
        $tokens->expects(self::never())->method('issue');

        $this->expectException(BusinessException::class);
        (new LoginMemberService($members, $tokens))->login('13800138003', 'secret123');
    }
}
