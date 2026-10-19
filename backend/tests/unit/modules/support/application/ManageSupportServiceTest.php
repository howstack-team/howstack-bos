<?php

declare(strict_types=1);

namespace tests\unit\modules\support\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\support\application\ManageSupportService;
use app\modules\support\contract\SupportRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ManageSupportServiceTest extends TestCase
{
    private function conversations(): \app\modules\support\application\TicketConversationService
    {
        return new \app\modules\support\application\TicketConversationService(
            $this->createStub(\app\modules\support\contract\TicketConversationRepositoryInterface::class),
            $this->createStub(\app\modules\service_instance\contract\MemberServiceRepositoryInterface::class),
            $this->createStub(\app\shared\contract\TransactionManagerInterface::class),
            $this->createStub(\app\modules\message\contract\MessagePublisherInterface::class));
    }

    public function testUnauthorizedAdministratorCannotReadTicketList(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([]);
        $repository = $this->createMock(SupportRepositoryInterface::class);
        $repository->expects(self::never())->method('tickets');
        $service = new ManageSupportService($repository, new AdminAccessControlService($grants), $this->conversations());

        $this->expectException(BusinessException::class);
        $service->tickets(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), '', null, 1, 10);
    }

    public function testFounderCanReadTicketTypes(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository = $this->createMock(SupportRepositoryInterface::class);
        $repository->expects(self::once())->method('catalog')->with('ticket_types', '', null, 1, 10)
            ->willReturn(['data' => [], 'total' => 0]);
        $service = new ManageSupportService($repository, new AdminAccessControlService($grants), $this->conversations());

        self::assertSame(0, $service->catalog(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true),
            'types', '', null, 1, 10)['total']);
    }

    public function testWhitespaceReplyIsRejectedBeforeWrite(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository = $this->createStub(SupportRepositoryInterface::class);

        $service = new ManageSupportService($repository, new AdminAccessControlService($grants), $this->conversations());

        $this->expectException(BusinessException::class);
        $service->reply(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 10, '   ', str_repeat('k', 32));
    }

    public function testViewOnlyAdministratorCannotReply(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([[
            'permissions' => ['admin.tickets.list.view'], 'data_scope' => 'all', 'department_ids' => [],
        ]]);
        $repository = $this->createStub(SupportRepositoryInterface::class);

        $service = new ManageSupportService($repository, new AdminAccessControlService($grants), $this->conversations());

        $this->expectException(BusinessException::class);
        $service->reply(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), 10, '处理结果', str_repeat('k', 32));
    }

    public function testReplyPermissionDoesNotGrantClosePermission(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([['permissions' => ['admin.tickets.list.view', 'admin.tickets.list.reply'], 'data_scope' => 'all', 'department_ids' => []]]);
        $service = new ManageSupportService($this->createStub(SupportRepositoryInterface::class), new AdminAccessControlService($grants), $this->conversations());
        $this->expectException(BusinessException::class);
        $service->close(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), 10, str_repeat('k', 32));
    }

    public function testClosePermissionDoesNotGrantReplyPermission(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([['permissions' => ['admin.tickets.list.view', 'admin.tickets.list.close'], 'data_scope' => 'all', 'department_ids' => []]]);
        $service = new ManageSupportService($this->createStub(SupportRepositoryInterface::class), new AdminAccessControlService($grants), $this->conversations());
        $this->expectException(BusinessException::class);
        $service->reply(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), 10, '回复', str_repeat('k', 32));
    }
}
