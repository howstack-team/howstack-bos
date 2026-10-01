<?php

declare(strict_types=1);

namespace tests\unit\modules\service_instance\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\service_instance\application\ViewServiceInstancesService;
use app\modules\service_instance\contract\ServiceInstanceRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ViewServiceInstancesServiceTest extends TestCase
{
    public function testAdministratorWithoutPermissionCannotListInstances(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([]);
        $instances = $this->createMock(ServiceInstanceRepositoryInterface::class);
        $instances->expects(self::never())->method('paginate');
        $service = new ViewServiceInstancesService(new AdminAccessControlService($access), $instances);

        $this->expectException(BusinessException::class);
        $service->paginate(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), 'cloud_server', '', null, 1, 10);
    }

    public function testFounderCanListSelectedProductType(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $instances = $this->createMock(ServiceInstanceRepositoryInterface::class);
        $instances->expects(self::once())->method('paginate')
            ->with('server_colocation', '机柜', 'active', 2, 20)
            ->willReturn(['data' => [], 'total' => 0]);
        $service = new ViewServiceInstancesService(new AdminAccessControlService($access), $instances);

        self::assertSame(0, $service->paginate(
            new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true),
            'server_colocation', '机柜', 'active', 2, 20,
        )['total']);
    }

    public function testDetailChecksPermissionForActualInstanceType(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([[
            'permissions' => ['admin.services.cloud-servers.view'],
            'data_scope' => 'all',
            'department_ids' => [],
        ]]);
        $instances = $this->createMock(ServiceInstanceRepositoryInterface::class);
        $instances->expects(self::once())->method('find')->with(42)
            ->willReturn(['id' => 42, 'product_type' => 'physical_server']);
        $service = new ViewServiceInstancesService(new AdminAccessControlService($access), $instances);

        $this->expectException(BusinessException::class);
        $service->detail(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), 42);
    }
}
