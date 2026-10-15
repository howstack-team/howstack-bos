<?php

declare(strict_types=1);

namespace app\modules\admin\access\application;

use app\exception\BusinessException;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\access\domain\PermissionCatalog;
use app\modules\admin\access\domain\SystemAccessDefaults;
use app\modules\admin\auth\domain\AdminAccount;
use app\shared\http\ApiErrorCode;

/**
 * 编排运营后台管理员、角色和部门管理用例。
 */
final readonly class AdminAccessManagementService
{
    /**
     * 初始化管理用例。
     * @param AdminAccessRepositoryInterface $repository 授权数据仓储
     * @param AdminAccessControlService      $access     请求级授权服务
     */
    public function __construct(
        private AdminAccessRepositoryInterface $repository,
        private AdminAccessControlService $access,
    ) {
    }

    /**
     * 分页查询当前管理员可见的账号。
     * @param AdminAccount $actor    当前管理员
     * @param string       $keyword  搜索词
     * @param int          $page     页码
     * @param int          $pageSize 每页数量
     * @return array{data: list<array<string, mixed>>, total: int} 账号分页
     */
    public function administrators(
        AdminAccount $actor,
        string $keyword,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $this->access->requirePermission($actor, 'admin.administrators.view');

        return $this->repository->administrators($this->access->scope($actor), $actor->id, $keyword, $page, $pageSize, $filters);
    }

    /**
     * 查询一个可见管理员。
     * @param AdminAccount $actor 当前管理员
     * @param int          $id    目标管理员标识
     * @return array<string, mixed> 管理员资料
     * @throws BusinessException 目标不存在或不在数据范围时抛出
     */
    public function administrator(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.administrators.view');
        $result = $this->repository->administrator($id, $this->access->scope($actor), $actor->id);
        if ($result === null) {
            throw $this->notFound();
        }

        return $result;
    }

    /**
     * 创建或更新管理员，不能通过此入口修改创始管理员或自身权限。
     * @param AdminAccount         $actor 当前管理员
     * @param int|null             $id    目标标识，新增时为空
     * @param array<string, mixed> $input 已验证的白名单字段
     * @return array<string, mixed> 保存后的管理员资料
     * @throws BusinessException 对象不可见、重复账号或越权时抛出
     */
    public function saveAdministrator(AdminAccount $actor, ?int $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.administrators.' . ($id === null ? 'create' : 'update'));
        $previous = $id === null ? null : $this->repository->administrator($id, $this->access->scope($actor), $actor->id);
        if ($id !== null && $previous === null) {
            throw $this->notFound();
        }
        if ($previous !== null && ($previous['is_founder'] || $id === $actor->id)) {
            throw $this->forbidden('不能在管理员管理中修改创始账号或自身权限');
        }
        $departmentId = $input['department_id'] ?? null;
        if ($departmentId === null && !$actor->isFounder) {
            throw $this->forbidden('非创始管理员创建或编辑账号时必须指定可管理部门');
        }
        $this->requireVisibleDepartment($actor, $departmentId);
        $roleIds = array_values(array_unique(array_map('intval', $input['role_ids'])));
        if ($roleIds === []) {
            throw $this->invalid('请至少分配一个角色');
        }
        foreach ($roleIds as $roleId) {
            $role = $this->repository->role($roleId);
            if ($role === null || $role['status'] !== 1) {
                throw $this->invalid('角色不存在或已停用');
            }
            if (($role['is_system'] ?? false) && !$actor->isFounder) {
                throw $this->forbidden('仅创始管理员可分配系统默认角色');
            }
            $this->access->requireGrantable($actor, $role['permissions'], $role['data_scope'], $role['department_ids'], $departmentId);
        }
        if ($this->repository->usernameExists($input['username'], $id)) {
            throw new BusinessException('管理员账号已存在', ApiErrorCode::CONFLICT, 409);
        }
        $employeeNo = trim((string) (array_key_exists('employee_no', $input) ? $input['employee_no'] : ($previous['employee_no'] ?? '')));
        $employeeNo = $employeeNo === '' ? null : $employeeNo;
        if ($employeeNo !== null && $this->repository->employeeNumberExists($employeeNo, $id)) {
            throw new BusinessException('工号已被其他系统用户使用', ApiErrorCode::CONFLICT, 409);
        }
        $data = [
            'username' => $input['username'],
            'nickname' => $input['nickname'],
            'employee_no' => $employeeNo,
            'mobile' => trim((string) (array_key_exists('mobile', $input) ? $input['mobile'] : ($previous['mobile'] ?? ''))) ?: null,
            'email' => trim((string) (array_key_exists('email', $input) ? $input['email'] : ($previous['email'] ?? ''))) ?: null,
            'description' => trim((string) (array_key_exists('description', $input) ? $input['description'] : ($previous['description'] ?? ''))),
            'department_id' => $departmentId,
            'status' => (int) $input['status'],
        ];
        if ($id === null) {
            $data['created_by'] = $actor->id;
            $data['is_founder'] = 0;
        }
        if (!empty($input['password'])) {
            $data['password_hash'] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        $savedId = $this->repository->saveAdministrator($id, $data, $roleIds);

        return $this->repository->rawAdministrator($savedId) ?? throw $this->notFound();
    }

    /**
     * 删除数据范围内且非创始、非自身的管理员。
     * @param AdminAccount $actor 当前管理员
     * @param int          $id    目标标识
     * @return void
     * @throws BusinessException 无权访问或目标受保护时抛出
     */
    public function deleteAdministrator(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.administrators.delete');
        $target = $this->repository->administrator($id, $this->access->scope($actor), $actor->id);
        if ($target === null) {
            throw $this->notFound();
        }
        if ($target['is_founder'] || $id === $actor->id) {
            throw $this->forbidden('不能删除创始管理员或当前账号');
        }
        $this->repository->deleteAdministrator($id);
    }

    /**
     * 查询所有角色。
     * @param AdminAccount $actor 当前管理员
     * @return list<array<string, mixed>> 角色列表
     */
    public function roles(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.roles.view');

        return $this->repository->roles();
    }

    /**
     * 查询单个角色。
     * @param AdminAccount $actor 当前管理员
     * @param int          $id    角色标识
     * @return array<string, mixed> 角色资料
     * @throws BusinessException 角色不存在时抛出
     */
    public function role(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.roles.view');

        return $this->repository->role($id) ?? throw $this->notFound();
    }

    /**
     * 创建或更新角色及其授权。
     * @param AdminAccount         $actor 当前管理员
     * @param int|null             $id    角色标识，新增时为空
     * @param array<string, mixed> $input 已验证字段
     * @return array<string, mixed> 保存后的角色
     * @throws BusinessException 权限码无效、角色已分配或授权越权时抛出
     */
    public function saveRole(AdminAccount $actor, ?int $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.roles.' . ($id === null ? 'create' : 'update'));
        $existing = $id === null ? null : $this->repository->role($id);
        if ($id !== null && $existing === null) {
            throw $this->notFound();
        }
        if ($existing !== null && ($existing['is_system'] ?? false)) {
            throw $this->forbidden('系统默认角色不能编辑');
        }
        if ($id === null && $input['code'] === SystemAccessDefaults::SUPER_ADMIN_ROLE_CODE) {
            throw $this->invalid('该角色标识为系统保留标识');
        }
        if ($id !== null && !$actor->isFounder && $this->repository->roleIsAssigned($id)) {
            throw $this->forbidden('非创始管理员不能修改已分配的角色');
        }
        $permissions = array_values(array_unique($input['permissions']));
        if (!PermissionCatalog::containsAll($permissions)) {
            throw $this->invalid('包含未发布的权限码');
        }
        $departmentIds = array_values(array_unique(array_map('intval', $input['department_ids'] ?? [])));
        if ($input['data_scope'] !== 'custom' && $departmentIds !== []) {
            throw $this->invalid('仅自定义数据范围可指定部门');
        }
        $availableIds = array_column($this->repository->departments(), 'id');
        if (array_diff($departmentIds, $availableIds) !== []) {
            throw $this->invalid('自定义部门不存在');
        }
        $this->access->requireGrantable($actor, $permissions, $input['data_scope'], $departmentIds);
        if ($this->repository->roleCodeExists($input['code'], $id)) {
            throw new BusinessException('角色标识已存在', ApiErrorCode::CONFLICT, 409);
        }
        $data = [
            'name' => $input['name'], 'code' => $input['code'],
            'sort_order' => (int) ($input['sort_order'] ?? ($existing['sort_order'] ?? 0)),
            'description' => trim((string) (array_key_exists('description', $input) ? $input['description'] : ($existing['description'] ?? ''))),
            'data_scope' => $input['data_scope'], 'status' => (int) $input['status'],
        ];
        $savedId = $this->repository->saveRole($id, $data, $permissions, $departmentIds);

        return $this->repository->role($savedId) ?? throw $this->notFound();
    }

    /**
     * 删除没有管理员引用的角色。
     * @param AdminAccount $actor 当前管理员
     * @param int          $id    角色标识
     * @return void
     * @throws BusinessException 角色不存在或仍被使用时抛出
     */
    public function deleteRole(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.roles.delete');
        $role = $this->repository->role($id);
        if ($role === null) {
            throw $this->notFound();
        }
        if ($role['is_system'] ?? false) {
            throw $this->forbidden('系统默认角色不能删除');
        }
        if ($this->repository->roleIsAssigned($id)) {
            throw new BusinessException('角色仍分配给管理员，不能删除', ApiErrorCode::CONFLICT, 409);
        }
        $this->repository->deleteRole($id);
    }

    /**
     * 返回允许当前管理员查看的部门节点。
     * @param AdminAccount $actor 当前管理员
     * @return list<array{id: int, parent_id: int|null, name: string, status: int, is_root: bool}> 部门节点
     */
    public function departments(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.departments.view');
        $nodes = $this->repository->departments();
        $scope = $this->access->scope($actor);

        return $scope->all ? $nodes : array_values(array_filter($nodes, static fn (array $row): bool => in_array($row['id'], $scope->departmentIds, true)));
    }

    /**
     * 创建或编辑部门；既有节点不允许通过此接口移动父级。
     * @param AdminAccount         $actor 当前管理员
     * @param int|null             $id    部门标识，新增时为空
     * @param array<string, mixed> $input 已验证字段
     * @return array{id: int, parent_id: int|null, name: string, sort_order: int, description: string, status: int, is_root: bool} 保存后的部门节点
     * @throws BusinessException 父部门或目标部门不在可管理范围时抛出
     */
    public function saveDepartment(AdminAccount $actor, ?int $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.departments.' . ($id === null ? 'create' : 'update'));
        $nodes = $this->repository->departments();
        $current = $id === null ? null : $this->findDepartment($nodes, $id);
        if ($id !== null && $current === null) {
            throw $this->notFound();
        }
        $root = null;
        foreach ($nodes as $node) {
            if ($node['is_root'] ?? false) {
                $root = $node;
                break;
            }
        }
        if ($root === null) {
            throw $this->invalid('系统根部门尚未初始化');
        }
        $parentId = $id === null ? ($input['parent_id'] ?? $root['id']) : $current['parent_id'];
        if ($id !== null) {
            $this->requireVisibleDepartment($actor, $id);
        }
        if ($current !== null && ($current['is_root'] ?? false) && (int) $input['status'] !== 1) {
            throw $this->forbidden('系统根部门不能停用');
        }
        if ($parentId === null && !($current['is_root'] ?? false)) {
            throw $this->invalid('新部门必须指定上级部门');
        }
        $this->requireVisibleDepartment($actor, $parentId);
        $savedId = $this->repository->saveDepartment($id, [
            'name' => $input['name'],
            'sort_order' => (int) ($input['sort_order'] ?? ($current['sort_order'] ?? 0)),
            'description' => trim((string) (array_key_exists('description', $input) ? $input['description'] : ($current['description'] ?? ''))),
            'parent_id' => $parentId, 'status' => (int) $input['status'],
        ]);

        return $this->findDepartment($this->repository->departments(), $savedId) ?? throw $this->notFound();
    }

    /**
     * 删除没有关联成员、子部门或角色授权的部门。
     * @param AdminAccount $actor 当前管理员
     * @param int          $id    部门标识
     * @return void
     * @throws BusinessException 部门不在范围或仍被使用时抛出
     */
    public function deleteDepartment(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.departments.delete');
        $this->requireVisibleDepartment($actor, $id);
        $target = $this->findDepartment($this->repository->departments(), $id);
        if ($target !== null && ($target['is_root'] ?? false)) {
            throw $this->forbidden('系统根部门不能删除');
        }
        if ($this->repository->departmentIsUsed($id)) {
            throw new BusinessException('部门仍有关联成员、子部门或授权', ApiErrorCode::CONFLICT, 409);
        }
        $this->repository->deleteDepartment($id);
    }

    /**
     * 查询当前发布的权限目录。
     * @param AdminAccount $actor 当前管理员
     * @return array<string, array{label: string, group: string}> 权限目录
     */
    public function permissionCatalog(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.roles.view');

        return PermissionCatalog::all();
    }

    /**
     * 校验部门存在且位于当前管理员可见范围。
     * @param AdminAccount $actor 当前管理员
     * @param int|null     $id    部门标识
     * @return void
     * @throws BusinessException 部门不存在或不在范围时抛出
     */
    private function requireVisibleDepartment(AdminAccount $actor, ?int $id): void
    {
        if ($id === null) {
            return;
        }
        $node = $this->findDepartment($this->repository->departments(), $id);
        if ($node === null || (!$this->access->scope($actor)->all
            && !in_array($id, $this->access->scope($actor)->departmentIds, true))) {
            throw $this->notFound();
        }
    }

    /**
     * 在已查询的部门列表中定位节点。
     * @param list<array{id: int, parent_id: int|null, name: string, sort_order: int, description: string, status: int, is_root: bool}> $nodes 部门节点
     * @param int $id 部门标识
     * @return array{id: int, parent_id: int|null, name: string, sort_order: int, description: string, status: int, is_root: bool}|null 部门节点
     */
    private function findDepartment(array $nodes, int $id): ?array
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * 创建资源不存在异常。
     * @return BusinessException 标准 404 异常
     */
    private function notFound(): BusinessException
    {
        return new BusinessException('资源不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    /**
     * 创建操作被拒绝异常。
     * @param string $message 可展示错误信息
     * @return BusinessException 标准 403 异常
     */
    private function forbidden(string $message): BusinessException
    {
        return new BusinessException($message, ApiErrorCode::FORBIDDEN, 403);
    }

    /**
     * 创建输入业务约束异常。
     * @param string $message 可展示错误信息
     * @return BusinessException 标准 422 异常
     */
    private function invalid(string $message): BusinessException
    {
        return new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422);
    }
}
