<?php

declare(strict_types=1);

namespace app\modules\admin\access\application;

use app\exception\BusinessException;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\access\domain\AdminDataScope;
use app\modules\admin\access\domain\PermissionCatalog;
use app\modules\admin\auth\domain\AdminAccount;
use app\shared\http\ApiErrorCode;

/**
 * 在每次请求中从持久化角色关系计算后台操作权限与数据范围。
 */
final readonly class AdminAccessControlService
{
    /**
     * 初始化授权服务。
     * @param AdminAccessRepositoryInterface $repository 授权数据仓储
     */
    public function __construct(private AdminAccessRepositoryInterface $repository)
    {
    }

    /**
     * 返回当前管理员的有效权限码。
     * @param AdminAccount $actor 已认证管理员
     * @return list<string> 有效权限码
     */
    public function permissions(AdminAccount $actor): array
    {
        if ($actor->isFounder) {
            return array_keys(PermissionCatalog::all());
        }
        $codes = [];
        $catalog = PermissionCatalog::all();
        foreach ($this->repository->grantsForAdmin($actor->id) as $role) {
            foreach ($role['permissions'] as $code) {
                if (isset($catalog[$code])) {
                    $codes[$code] = true;
                }
            }
        }

        return array_keys($codes);
    }

    /**
     * 检查当前管理员是否有指定操作权限。
     * @param AdminAccount $actor 已认证管理员
     * @param string       $code  所需权限码
     * @return void
     * @throws BusinessException 缺少权限时抛出
     */
    public function requirePermission(AdminAccount $actor, string $code): void
    {
        if (!$actor->isFounder && !in_array($code, $this->permissions($actor), true)) {
            throw new BusinessException('无权执行此操作', ApiErrorCode::FORBIDDEN, 403);
        }
    }

    /**
     * 合并启用角色的数据范围，未配置角色时拒绝所有行。
     * @param AdminAccount $actor 已认证管理员
     * @return AdminDataScope 可应用于查询的数据范围
     */
    public function scope(AdminAccount $actor): AdminDataScope
    {
        if ($actor->isFounder) {
            return new AdminDataScope(true, true, []);
        }
        $all = false;
        $self = false;
        $ids = [];
        $departments = $this->repository->departments();
        foreach ($this->repository->grantsForAdmin($actor->id) as $role) {
            switch ($role['data_scope']) {
                case 'all':
                    $all = true;
                    break;
                case 'self':
                    $self = true;
                    break;
                case 'department':
                    if ($actor->departmentId !== null) {
                        $ids[] = $actor->departmentId;
                    }
                    break;
                case 'department_and_children':
                    if ($actor->departmentId !== null) {
                        $ids = array_merge($ids, self::descendantIds($departments, $actor->departmentId));
                    }
                    break;
                case 'custom':
                    $ids = array_merge($ids, $role['department_ids']);
                    break;
            }
        }

        return new AdminDataScope($all, $self, array_values(array_unique(array_map('intval', $ids))));
    }

    /**
     * 从邻接部门树计算指定节点及其全部后代，循环关系按已访问集合中断。
     * @param list<array{id: int, parent_id: int|null, name: string, status: int}> $departments 部门节点
     * @param int $rootId 起始部门标识
     * @return list<int> 起始部门及后代标识
     */
    public static function descendantIds(array $departments, int $rootId): array
    {
        $ids = [$rootId];
        $seen = [$rootId => true];
        for ($index = 0; $index < count($ids); $index++) {
            foreach ($departments as $department) {
                if ($department['parent_id'] === $ids[$index] && !isset($seen[$department['id']])) {
                    $seen[$department['id']] = true;
                    $ids[] = $department['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * 验证待授权角色不会超出操作人的权限与数据可见范围。
     * @param AdminAccount         $actor       操作管理员
     * @param list<string>         $permissions 待授予权限码
     * @param string               $mode        待授予数据范围模式
     * @param list<int>            $customIds   自定义部门标识
     * @param int|null             $targetDepartmentId 接受角色管理员的部门；编辑角色时为空
     * @return void
     * @throws BusinessException 授权提升时抛出
     */
    public function requireGrantable(
        AdminAccount $actor,
        array $permissions,
        string $mode,
        array $customIds,
        ?int $targetDepartmentId = null,
    ): void {
        if ($actor->isFounder) {
            return;
        }
        if (array_diff($permissions, $this->permissions($actor)) !== []) {
            throw new BusinessException('不能授予高于自身的操作权限', ApiErrorCode::FORBIDDEN, 403);
        }
        $ownScope = $this->scope($actor);
        if ($mode === 'all' && !$ownScope->all) {
            throw new BusinessException('不能授予高于自身的数据范围', ApiErrorCode::FORBIDDEN, 403);
        }
        if ($mode === 'custom' && !$ownScope->all && array_diff($customIds, $ownScope->departmentIds) !== []) {
            throw new BusinessException('自定义部门超出自身的数据范围', ApiErrorCode::FORBIDDEN, 403);
        }
        if (in_array($mode, ['department', 'department_and_children'], true) && !$ownScope->all) {
            // 相对范围会随着受授者部门变化；非创始管理员只能分配明确限定的自定义范围。
            throw new BusinessException('不能授予相对部门范围，请改用自定义部门范围', ApiErrorCode::FORBIDDEN, 403);
        }
        if ($mode === 'self' && $targetDepartmentId !== null && !$ownScope->all
            && !in_array($targetDepartmentId, $ownScope->departmentIds, true)) {
            throw new BusinessException('目标管理员不在可管理部门范围内', ApiErrorCode::FORBIDDEN, 403);
        }
    }
}
