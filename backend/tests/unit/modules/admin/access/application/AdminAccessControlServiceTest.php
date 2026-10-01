<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\access\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use PHPUnit\Framework\TestCase;

/**
 * 验证多角色授权取并集、部门范围和越权授予保护。
 */
final class AdminAccessControlServiceTest extends TestCase
{
    /**
     * 验证多个角色的操作权限与行级范围合并。
     * @return void
     */
    public function testCombinesRolePermissionsAndDepartmentScopes(): void
    {
        $repository = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([
            ['permissions' => ['admin.administrators.view'], 'data_scope' => 'department_and_children', 'department_ids' => []],
            ['permissions' => ['admin.roles.view'], 'data_scope' => 'custom', 'department_ids' => [9]],
        ]);
        $repository->method('departments')->willReturn([
            ['id' => 2, 'parent_id' => null, 'name' => '总部', 'status' => 1],
            ['id' => 3, 'parent_id' => 2, 'name' => '运维', 'status' => 1],
            ['id' => 9, 'parent_id' => null, 'name' => '财务', 'status' => 1],
        ]);
        $service = new AdminAccessControlService($repository);
        $actor = new AdminAccount(7, 'staff01', '员工', 'hash', 1, null, false, 2);

        self::assertSame(['admin.administrators.view', 'admin.roles.view'], $service->permissions($actor));
        self::assertSame([2, 3, 9], $service->scope($actor)->departmentIds);
        self::assertFalse($service->scope($actor)->all);
    }

    /**
     * 验证未授权账号默认没有操作权限和数据范围。
     * @return void
     */
    public function testDeniesAdministratorWithoutRoles(): void
    {
        $repository = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([]);
        $repository->method('departments')->willReturn([]);
        $service = new AdminAccessControlService($repository);
        $actor = new AdminAccount(8, 'staff02', '员工', 'hash', 1, null);

        self::assertSame([], $service->permissions($actor));
        self::assertSame([], $service->scope($actor)->departmentIds);
        $this->expectException(BusinessException::class);
        $service->requirePermission($actor, 'admin.administrators.view');
    }

    /**
     * 验证非创始管理员不能授予自身没有的权限码。
     * @return void
     */
    public function testRejectsPrivilegeEscalation(): void
    {
        $repository = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([
            ['permissions' => ['admin.roles.create'], 'data_scope' => 'self', 'department_ids' => []],
        ]);
        $service = new AdminAccessControlService($repository);
        $actor = new AdminAccount(8, 'staff02', '员工', 'hash', 1, null, false, 2);

        $this->expectException(BusinessException::class);
        $service->requireGrantable($actor, ['admin.administrators.delete'], 'self', []);
    }

    /**
     * 验证创始管理员无角色时仍具有完整权限和全部数据范围。
     * @return void
     */
    public function testFounderRetainsFullAccess(): void
    {
        $repository = $this->createStub(AdminAccessRepositoryInterface::class);
        $service = new AdminAccessControlService($repository);
        $founder = new AdminAccount(1, 'admin01', '创始人', 'hash', 1, null, true);

        self::assertContains('admin.roles.delete', $service->permissions($founder));
        self::assertTrue($service->scope($founder)->all);
    }

    /**
     * 同一个管理员对象在角色权限撤销后立即失去操作权限。
     * @return void
     */
    public function testPermissionChangesTakeEffectWithoutRefreshingJwt(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('grantsForAdmin')->willReturnOnConsecutiveCalls(
            [['permissions' => ['admin.roles.view'], 'data_scope' => 'self', 'department_ids' => []]],
            [],
        );
        $service = new AdminAccessControlService($repository);
        $actor = new AdminAccount(8, 'staff02', '员工', 'hash', 1, null, false, 2);

        $service->requirePermission($actor, 'admin.roles.view');
        $this->expectException(BusinessException::class);
        $service->requirePermission($actor, 'admin.roles.view');
    }
}
