<?php

declare(strict_types=1);

namespace app\modules\admin\access\infrastructure;

use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\access\infrastructure\model\AdminDepartmentModel;
use app\modules\admin\access\infrastructure\model\AdminRoleModel;
use app\modules\admin\access\infrastructure\model\AdminUserRoleModel;
use app\modules\admin\access\infrastructure\model\AdminRolePermissionModel;
use app\modules\admin\access\infrastructure\model\AdminRoleDepartmentModel;
use support\Model;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\access\domain\AdminDataScope;
use app\modules\admin\access\domain\PermissionCatalog;
use app\modules\admin\access\domain\SystemAccessDefaults;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use support\Db;

/**
 * 使用当前系统数据库存取后台授权关系并在 SQL 层约束管理员行范围。
 */
final class DatabaseAdminAccessRepository implements AdminAccessRepositoryInterface
{
    /**
     * 读取启用角色及其权限和自定义部门。
     * @param int $adminId 管理员标识
     * @return list<array<string, mixed>> 授权角色列表
     */
    public function grantsForAdmin(int $adminId): array
    {
        $roles = AdminRoleModel::query()->whereHas('users', static fn (EloquentBuilder $query) => $query->where('admin_users.id', $adminId))
            ->where('status', 1)->with(['permissions', 'departmentAssignments'])->get();
        return $roles->map($this->decorateRole(...))->all();
    }

    /**
     * 读取部门树的基础节点。
     * @return list<array{id: int, parent_id: int|null, name: string, sort_order: int, description: string, status: int, is_root: bool}> 部门列表
     */
    public function departments(): array
    {
        return array_map(static fn (Model $row): array => [
            'id' => (int) $row->id,
            'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            'name' => (string) $row->name,
            'sort_order' => (int) $row->sort_order,
            'description' => (string) $row->description,
            'status' => (int) $row->status,
            'is_root' => $row->system_key === SystemAccessDefaults::ROOT_DEPARTMENT_KEY,
        ], AdminDepartmentModel::query()->orderBy('sort_order')->orderBy('id')->get()->all());
    }

    /**
     * 在 SQL 查询阶段限制管理员列表范围，分页和总数使用同一约束。
     * @param AdminDataScope $scope    当前数据范围
     * @param int            $actorId  当前管理员标识
     * @param string         $keyword  搜索词
     * @param int            $page     页码
     * @param int            $pageSize 每页数量
     * @return array{data: list<array<string, mixed>>, total: int} 管理员列表及总数
     */
    public function administrators(AdminDataScope $scope, int $actorId, string $keyword, int $page, int $pageSize): array
    {
        $query = AdminUserModel::query()->with('roleAssignments');
        $this->constrainAdministrators($query, $scope, $actorId);
        if ($keyword !== '') {
            $query->where(static function (Builder|EloquentBuilder $nested) use ($keyword): void {
                $nested->where('username', 'like', '%' . $keyword . '%')
                    ->orWhere('nickname', 'like', '%' . $keyword . '%')
                    ->orWhere('employee_no', 'like', '%' . $keyword . '%');
            });
        }
        $total = (clone $query)->count();
        $rows = $query->select(['id', 'username', 'nickname', 'employee_no', 'mobile', 'email', 'description', 'department_id', 'created_by', 'is_founder', 'status', 'last_login_at', 'created_at'])
            ->orderBy('id', 'desc')->forPage($page, $pageSize)->get();

        return ['data' => array_map(fn (Model $row): array => $this->decorateAdministrator($row), $rows->all()), 'total' => $total];
    }

    /**
     * 查询范围内管理员，范围外与不存在均返回 null。
     * @param int            $id      目标管理员标识
     * @param AdminDataScope $scope   当前数据范围
     * @param int            $actorId 当前管理员标识
     * @return array<string, mixed>|null 管理员资料
     */
    public function administrator(int $id, AdminDataScope $scope, int $actorId): ?array
    {
        $query = AdminUserModel::query()->with('roleAssignments')->where('id', $id);
        $this->constrainAdministrators($query, $scope, $actorId);
        $row = $query->first();

        return $row instanceof Model ? $this->decorateAdministrator($row) : null;
    }

    /**
     * 读取管理员原始资料，仅供授权前的内部存在性判断使用。
     * @param int $id 管理员标识
     * @return array<string, mixed>|null 管理员资料
     */
    public function rawAdministrator(int $id): ?array
    {
        $row = AdminUserModel::query()->with('roleAssignments')->where('id', $id)->first();

        return $row instanceof Model ? $this->decorateAdministrator($row) : null;
    }

    /**
     * 列出所有角色及其授权。
     * @return list<array<string, mixed>> 角色列表
     */
    public function roles(): array
    {
        return array_map(fn (Model $row): array => $this->decorateRole($row), AdminRoleModel::query()->with(['permissions', 'departmentAssignments'])->orderBy('sort_order')->orderBy('id')->get()->all());
    }

    /**
     * 查询角色资料及授权。
     * @param int $id 角色标识
     * @return array<string, mixed>|null 角色资料
     */
    public function role(int $id): ?array
    {
        $row = AdminRoleModel::query()->with(['permissions', 'departmentAssignments'])->where('id', $id)->first();

        return $row instanceof Model ? $this->decorateRole($row) : null;
    }

    /**
     * 在事务中保存角色、权限和自定义部门。
     * @param int|null              $id            角色标识，新增时为空
     * @param array<string, mixed>  $data          角色字段
     * @param list<string>          $permissions   权限码
     * @param list<int>             $departmentIds 自定义部门标识
     * @return int 保存后的角色标识
     */
    public function saveRole(?int $id, array $data, array $permissions, array $departmentIds): int
    {
        return Db::transaction(static function () use ($id, $data, $permissions, $departmentIds): int {
            if ($id === null) {
                $id = (int) AdminRoleModel::query()->create($data)->getKey();
            } else {
                AdminRoleModel::query()->findOrFail($id)->fill($data)->save();
            }
            AdminRolePermissionModel::query()->where('role_id', $id)->delete();
            AdminRoleDepartmentModel::query()->where('role_id', $id)->delete();
            if ($permissions !== []) {
                AdminRolePermissionModel::query()->insert(array_map(static fn (string $code): array => ['role_id' => $id, 'permission_code' => $code], $permissions));
            }
            if ($departmentIds !== []) {
                AdminRoleDepartmentModel::query()->insert(array_map(static fn (int $departmentId): array => ['role_id' => $id, 'department_id' => $departmentId], $departmentIds));
            }

            return $id;
        });
    }

    /**
     * 删除未分配的角色及关系。
     * @param int $id 角色标识
     * @return void
     */
    public function deleteRole(int $id): void
    {
        Db::transaction(static function () use ($id): void {
            AdminRolePermissionModel::query()->where('role_id', $id)->delete();
            AdminRoleDepartmentModel::query()->where('role_id', $id)->delete();
            AdminRoleModel::query()->where('id', $id)->delete();
        });
    }

    /**
     * 在事务中保存管理员和多角色关系。
     * @param int|null             $id      管理员标识，新增时为空
     * @param array<string, mixed> $data    可写管理员字段
     * @param list<int>            $roleIds 角色标识
     * @return int 保存后的管理员标识
     */
    public function saveAdministrator(?int $id, array $data, array $roleIds): int
    {
        return Db::transaction(static function () use ($id, $data, $roleIds): int {
            if ($id === null) {
                $id = (int) AdminUserModel::query()->create($data)->getKey();
            } else {
                AdminUserModel::query()->findOrFail($id)->fill($data)->save();
            }
            AdminUserRoleModel::query()->where('admin_user_id', $id)->delete();
            if ($roleIds !== []) {
                AdminUserRoleModel::query()->insert(array_map(static fn (int $roleId): array => ['admin_user_id' => $id, 'role_id' => $roleId], $roleIds));
            }

            return $id;
        });
    }

    /**
     * 删除管理员及其角色关系。
     * @param int $id 管理员标识
     * @return void
     */
    public function deleteAdministrator(int $id): void
    {
        Db::transaction(static function () use ($id): void {
            AdminUserRoleModel::query()->where('admin_user_id', $id)->delete();
            AdminUserModel::query()->where('id', $id)->delete();
        });
    }

    /**
     * 保存部门节点。
     * @param int|null             $id   部门标识，新增时为空
     * @param array<string, mixed> $data 可写部门字段
     * @return int 保存后的部门标识
     */
    public function saveDepartment(?int $id, array $data): int
    {
        if ($id === null) {
            return (int) AdminDepartmentModel::query()->create($data)->getKey();
        }
        AdminDepartmentModel::query()->findOrFail($id)->fill($data)->save();

        return $id;
    }

    /**
     * 删除空部门节点。
     * @param int $id 部门标识
     * @return void
     */
    public function deleteDepartment(int $id): void
    {
        AdminDepartmentModel::query()->where('id', $id)->delete();
    }

    /**
     * 判断角色是否仍有关联管理员。
     * @param int $id 角色标识
     * @return bool 有关联时返回 true
     */
    public function roleIsAssigned(int $id): bool
    {
        return AdminUserRoleModel::query()->where('role_id', $id)->exists();
    }

    /**
     * 判断部门是否仍有关联成员、子部门或授权。
     * @param int $id 部门标识
     * @return bool 有关联时返回 true
     */
    public function departmentIsUsed(int $id): bool
    {
        return AdminDepartmentModel::query()->where('parent_id', $id)->exists()
            || AdminUserModel::query()->where('department_id', $id)->exists()
            || AdminRoleDepartmentModel::query()->where('department_id', $id)->exists();
    }

    /**
     * 查询角色标识是否已被其他角色使用。
     * @param string   $code     角色标识
     * @param int|null $exceptId 排除的角色标识
     * @return bool 已占用时返回 true
     */
    public function roleCodeExists(string $code, ?int $exceptId): bool
    {
        $query = AdminRoleModel::query()->where('code', $code);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->exists();
    }

    /**
     * 查询管理员账号是否已被其他人使用。
     * @param string   $username 管理员账号
     * @param int|null $exceptId 排除的管理员标识
     * @return bool 已占用时返回 true
     */
    public function usernameExists(string $username, ?int $exceptId): bool
    {
        $query = AdminUserModel::query()->where('username', $username);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->exists();
    }

    /**
     * 查询非空工号是否已被其他系统用户使用。
     * @param string   $employeeNo 工号
     * @param int|null $exceptId   排除的管理员标识
     * @return bool 已占用时返回 true
     */
    public function employeeNumberExists(string $employeeNo, ?int $exceptId): bool
    {
        $query = AdminUserModel::query()->where('employee_no', $employeeNo);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->exists();
    }

    /**
     * 为管理员查询添加行级范围约束。
     * @param Builder        $query   当前查询
     * @param AdminDataScope $scope   可见范围
     * @param int            $actorId 当前管理员标识
     * @return void
     */
    private function constrainAdministrators(Builder|EloquentBuilder $query, AdminDataScope $scope, int $actorId): void
    {
        if ($scope->all) {
            return;
        }
        $query->where(static function (Builder|EloquentBuilder $nested) use ($scope, $actorId): void {
            if ($scope->self) {
                $nested->where('id', $actorId);
            }
            if ($scope->departmentIds !== []) {
                $scope->self ? $nested->orWhereIn('department_id', $scope->departmentIds) : $nested->whereIn('department_id', $scope->departmentIds);
            }
            if (!$scope->self && $scope->departmentIds === []) {
                $nested->whereRaw('1 = 0');
            }
        });
    }

    /**
     * 为角色附上授权关系。
     * @param AdminRoleModel $model 已预加载授权关系的角色
     * @return array<string, mixed> 角色公开资料
     */
    private function decorateRole(AdminRoleModel $model): array
    {
        $row = array_intersect_key($model->getAttributes(), array_flip([
            'id', 'name', 'code', 'sort_order', 'description', 'data_scope', 'status', 'is_system', 'created_at', 'updated_at',
        ]));
        $id = (int) $row['id'];
        $row['id'] = $id;
        $row['status'] = (int) $row['status'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_system'] = (bool) $row['is_system'];
        $row['permissions'] = $row['is_system']
            ? array_keys(PermissionCatalog::all())
            : $model->permissions->pluck('permission_code')->all();
        $row['department_ids'] = array_map('intval', $model->departmentAssignments->pluck('department_id')->all());

        return $row;
    }

    /**
     * 为管理员附上角色标识并剔除密码哈希。
     * @param AdminUserModel $model 已预加载角色关系的管理员
     * @return array<string, mixed> 可安全返回的管理员资料
     */
    private function decorateAdministrator(AdminUserModel $model): array
    {
        $row = array_intersect_key($model->getAttributes(), array_flip([
            'id', 'username', 'nickname', 'employee_no', 'mobile', 'email', 'description', 'department_id',
            'created_by', 'is_founder', 'status', 'last_login_at', 'created_at', 'updated_at',
        ]));
        $row['id'] = (int) $row['id'];
        $row['department_id'] = $row['department_id'] === null ? null : (int) $row['department_id'];
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        $row['is_founder'] = (bool) $row['is_founder'];
        $row['status'] = (int) $row['status'];
        $row['role_ids'] = array_map('intval', $model->roleAssignments->pluck('role_id')->all());

        return $row;
    }
}
