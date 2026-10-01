<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\access\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\application\AdminAccessManagementService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use PHPUnit\Framework\TestCase;

/**
 * 验证管理员管理入口的创始人保护和范围外对象处理。
 */
final class AdminAccessManagementServiceTest extends TestCase
{
    /**
     * 创建角色时允许先不授予操作权限，随后再单独分配。
     * @return void
     */
    public function testRoleCanBeCreatedBeforePermissionsAreAssigned(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('departments')->willReturn([]);
        $repository->expects(self::once())->method('role')->with(12)->willReturn([
            'id' => 12, 'name' => '测试角色', 'code' => 'test_role', 'description' => '',
            'data_scope' => 'self', 'status' => 1, 'permissions' => [], 'department_ids' => [],
        ]);
        $repository->expects(self::once())->method('saveRole')
            ->with(null, self::callback(static fn (array $data): bool => $data['data_scope'] === 'self'
                && $data['sort_order'] === 3 && $data['description'] === '测试说明'), [], [])
            ->willReturn(12);
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        $saved = $service->saveRole($this->founder(), null, [
            'name' => '测试角色', 'code' => 'test_role', 'sort_order' => 3, 'description' => '测试说明',
            'data_scope' => 'self', 'status' => 1, 'permissions' => [], 'department_ids' => [],
        ]);

        self::assertSame([], $saved['permissions']);
    }

    /**
     * 创始管理员不能被删除，即使执行人拥有全部权限。
     * @return void
     */
    public function testFounderCannotBeDeleted(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('administrator')->willReturn(['id' => 1, 'is_founder' => true]);
        $repository->expects(self::never())->method('deleteAdministrator');
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        try {
            $service->deleteAdministrator($this->founder(), 1);
            self::fail('创始管理员删除操作应被拒绝');
        } catch (BusinessException $exception) {
            self::assertSame(403, $exception->httpStatus());
        }
    }

    /**
     * 系统内置角色不能通过普通角色接口修改或删除。
     * @return void
     */
    public function testSystemRoleCannotBeModifiedOrDeleted(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('role')->with(3)->willReturn(['id' => 3, 'is_system' => true]);
        $repository->expects(self::never())->method('saveRole');
        $repository->expects(self::never())->method('deleteRole');
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $service->saveRole($this->founder(), 3, []);
                } else {
                    $service->deleteRole($this->founder(), 3);
                }
                self::fail('系统角色操作应被拒绝');
            } catch (BusinessException $exception) {
                self::assertSame(403, $exception->httpStatus());
            }
        }
    }

    /**
     * 系统根部门不能停用或删除，普通部门必须位于根部门之下。
     * @return void
     */
    public function testRootDepartmentIsProtectedAndNewDepartmentHasParent(): void
    {
        $root = ['id' => 1, 'parent_id' => null, 'name' => '总部', 'status' => 1, 'is_root' => true];
        $child = ['id' => 2, 'parent_id' => 1, 'name' => '业务部', 'status' => 1, 'is_root' => false];
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('departments')->willReturn([$root, $child]);
        $repository->expects(self::once())->method('saveDepartment')
            ->with(null, self::callback(static fn (array $data): bool => $data['parent_id'] === 1))
            ->willReturn(2);
        $repository->expects(self::never())->method('deleteDepartment');
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        $created = $service->saveDepartment($this->founder(), null, ['name' => '业务部', 'parent_id' => null, 'status' => 1]);
        self::assertSame(1, $created['parent_id']);
        foreach (['disable', 'delete'] as $operation) {
            try {
                if ($operation === 'disable') {
                    $service->saveDepartment($this->founder(), 1, ['name' => '总部', 'status' => 0]);
                } else {
                    $service->deleteDepartment($this->founder(), 1);
                }
                self::fail('系统根部门操作应被拒绝');
            } catch (BusinessException $exception) {
                self::assertSame(403, $exception->httpStatus());
            }
        }
    }

    /**
     * 根部门可修改排序和描述，但仍保持无上级部门。
     * @return void
     */
    public function testRootDepartmentMetadataCanBeEdited(): void
    {
        $root = ['id' => 1, 'parent_id' => null, 'name' => '总部', 'sort_order' => 0,
            'description' => '', 'status' => 1, 'is_root' => true];
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('departments')->willReturn([$root]);
        $repository->expects(self::once())->method('saveDepartment')
            ->with(1, self::callback(static fn (array $data): bool => $data['parent_id'] === null
                && $data['sort_order'] === 4 && $data['description'] === '集团总部'))
            ->willReturn(1);
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        $saved = $service->saveDepartment($this->founder(), 1, [
            'name' => '总部', 'sort_order' => 4, 'description' => '集团总部', 'status' => 1,
        ]);

        self::assertSame(1, $saved['id']);
    }

    /**
     * 范围外账号详情与不存在账号统一返回 404。
     * @return void
     */
    public function testOutOfScopeAdministratorReturnsNotFound(): void
    {
        $repository = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([
            ['permissions' => ['admin.administrators.view'], 'data_scope' => 'self', 'department_ids' => []],
        ]);
        $repository->method('administrator')->willReturn(null);
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        try {
            $service->administrator($this->staff(), 99);
            self::fail('范围外详情应被拒绝');
        } catch (BusinessException $exception) {
            self::assertSame(404, $exception->httpStatus());
        }
    }

    /**
     * 范围外账号更新在写入前结束。
     * @return void
     */
    public function testOutOfScopeAdministratorCannotBeUpdated(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([
            ['permissions' => ['admin.administrators.update'], 'data_scope' => 'self', 'department_ids' => []],
        ]);
        $repository->method('administrator')->willReturn(null);
        $repository->expects(self::never())->method('saveAdministrator');
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        try {
            $service->saveAdministrator($this->staff(), 99, []);
            self::fail('范围外写入应被拒绝');
        } catch (BusinessException $exception) {
            self::assertSame(404, $exception->httpStatus());
        }
    }

    /**
     * 已停用的角色不能继续分配给管理员。
     * @return void
     */
    public function testDisabledRoleCannotBeAssigned(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('role')->willReturn(['status' => 0]);
        $repository->expects(self::never())->method('saveAdministrator');
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        try {
            $service->saveAdministrator($this->founder(), null, [
                'username' => 'staff03', 'nickname' => '员工', 'department_id' => null,
                'status' => 1, 'role_ids' => [5], 'password' => 'password123',
            ]);
            self::fail('停用角色不应被分配');
        } catch (BusinessException $exception) {
            self::assertSame(422, $exception->httpStatus());
        }
    }

    /**
     * 工号可选，填写时去除首尾空格，空白值存为 NULL。
     * @return void
     */
    public function testEmployeeNumberIsOptionalAndNormalized(): void
    {
        foreach ([['employee_no' => ' E-001 '], ['employee_no' => '   '], []] as $input) {
            $expected = trim($input['employee_no'] ?? '') ?: null;
            $repository = $this->createMock(AdminAccessRepositoryInterface::class);
            $repository->method('role')->willReturn([
                'id' => 5, 'status' => 1, 'is_system' => false,
                'permissions' => [], 'data_scope' => 'self', 'department_ids' => [],
            ]);
            $repository->expects(self::once())->method('saveAdministrator')
                ->with(null, self::callback(static fn (array $data): bool => $data['employee_no'] === $expected), [5])
                ->willReturn(8);
            $repository->method('rawAdministrator')->willReturn(['id' => 8, 'employee_no' => $expected]);
            $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

            $saved = $service->saveAdministrator($this->founder(), null, [
                'username' => 'staff03', 'nickname' => '员工', 'department_id' => null,
                'status' => 1, 'role_ids' => [5], 'password' => 'password123',
            ] + $input);

            self::assertSame($expected, $saved['employee_no']);
        }
    }

    /**
     * 系统用户的可选联系方式和描述会进入持久化字段。
     * @return void
     */
    public function testOptionalContactFieldsAreSaved(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('role')->willReturn([
            'id' => 5, 'status' => 1, 'is_system' => false,
            'permissions' => [], 'data_scope' => 'self', 'department_ids' => [],
        ]);
        $repository->expects(self::once())->method('saveAdministrator')
            ->with(null, self::callback(static fn (array $data): bool => $data['mobile'] === '+8613800123456'
                && $data['email'] === 'user@example.com' && $data['description'] === '一线客服'), [5])
            ->willReturn(8);
        $repository->method('rawAdministrator')->willReturn(['id' => 8]);
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        $service->saveAdministrator($this->founder(), null, [
            'username' => 'staff03', 'nickname' => '员工', 'department_id' => null,
            'mobile' => '+8613800123456', 'email' => 'user@example.com', 'description' => '一线客服',
            'status' => 1, 'role_ids' => [5], 'password' => 'password123',
        ]);
    }

    /**
     * 已分配给其他用户的工号不能再次使用。
     * @return void
     */
    public function testDuplicateEmployeeNumberIsRejected(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('role')->willReturn([
            'id' => 5, 'status' => 1, 'is_system' => false,
            'permissions' => [], 'data_scope' => 'self', 'department_ids' => [],
        ]);
        $repository->expects(self::once())->method('employeeNumberExists')->with('E-001', null)->willReturn(true);
        $repository->expects(self::never())->method('saveAdministrator');
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        try {
            $service->saveAdministrator($this->founder(), null, [
                'username' => 'staff03', 'nickname' => '员工', 'employee_no' => 'E-001',
                'department_id' => null, 'status' => 1, 'role_ids' => [5], 'password' => 'password123',
            ]);
            self::fail('重复工号应被拒绝');
        } catch (BusinessException $exception) {
            self::assertSame(409, $exception->httpStatus());
        }
    }

    /**
     * 普通管理员即使拥有账号创建权限，也不能分配系统超级管理员角色。
     * @return void
     */
    public function testNonFounderCannotAssignSystemRole(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturn([
            ['permissions' => ['admin.administrators.create'], 'data_scope' => 'all', 'department_ids' => []],
        ]);
        $repository->method('departments')->willReturn([
            ['id' => 1, 'parent_id' => null, 'name' => '总部', 'status' => 1, 'is_root' => true],
        ]);
        $repository->expects(self::once())->method('role')->with(3)->willReturn(['id' => 3, 'status' => 1, 'is_system' => true]);
        $repository->expects(self::never())->method('saveAdministrator');
        $service = new AdminAccessManagementService($repository, new AdminAccessControlService($repository));

        try {
            $service->saveAdministrator($this->staff(), null, [
                'username' => 'staff03', 'nickname' => '员工', 'department_id' => 1,
                'status' => 1, 'role_ids' => [3], 'password' => 'password123',
            ]);
            self::fail('系统角色只应由创始管理员分配');
        } catch (BusinessException $exception) {
            self::assertSame(403, $exception->httpStatus());
        }
    }

    /**
     * 生成测试使用的创始管理员。
     * @return AdminAccount 创始管理员
     */
    private function founder(): AdminAccount
    {
        return new AdminAccount(1, 'admin01', '创始人', 'hash', 1, null, true);
    }

    /**
     * 生成测试使用的普通管理员。
     * @return AdminAccount 普通管理员
     */
    private function staff(): AdminAccount
    {
        return new AdminAccount(7, 'staff01', '员工', 'hash', 1, null, false, 2);
    }
}
