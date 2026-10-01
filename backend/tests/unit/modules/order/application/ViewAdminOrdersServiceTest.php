<?php

declare(strict_types=1);

namespace tests\unit\modules\order\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\order\application\ViewAdminOrdersService;
use app\modules\order\contract\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/** 验证订单列表与订单项详情只能由获授权的管理员读取。 */
final class ViewAdminOrdersServiceTest extends TestCase
{
    public function testUnauthorizedUserCannotReadOrders(): void
    {
        $access = $this->createMock(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([]);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->expects(self::never())->method('paginate');
        $orders->expects(self::never())->method('findWithItems');
        $service = new ViewAdminOrdersService(new AdminAccessControlService($access), $orders);
        $actor = new AdminAccount(7, 'operator', '运营', 'hash', 1, null);

        try {
            $service->paginate($actor, '', null, 1, 10);
            self::fail('无权限用户不能查看订单列表');
        } catch (BusinessException $exception) {
            self::assertSame(403, $exception->httpStatus());
        }
        try {
            $service->detail($actor, 1);
            self::fail('无权限用户不能查看订单项');
        } catch (BusinessException $exception) {
            self::assertSame(403, $exception->httpStatus());
        }
    }

    public function testFounderCanReadOrderAndItems(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->expects(self::once())->method('paginate')->with('A001', 'paid', 2, 20)
            ->willReturn(['data' => [], 'total' => 0]);
        $orders->expects(self::once())->method('findWithItems')->with(3)
            ->willReturn(['id' => 3, 'items' => [['id' => 4]]]);
        $service = new ViewAdminOrdersService(new AdminAccessControlService($access), $orders);
        $founder = new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true);

        self::assertSame(0, $service->paginate($founder, 'A001', 'paid', 2, 20)['total']);
        self::assertSame(4, $service->detail($founder, 3)['items'][0]['id']);
    }

    public function testMissingOrderReturnsNotFound(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findWithItems')->willReturn(null);
        $service = new ViewAdminOrdersService(new AdminAccessControlService($access), $orders);
        $founder = new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true);

        try {
            $service->detail($founder, 99);
            self::fail('不存在的订单必须返回 404');
        } catch (BusinessException $exception) {
            self::assertSame(404, $exception->httpStatus());
        }
    }
}
