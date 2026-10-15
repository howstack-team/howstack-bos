<?php

declare(strict_types=1);

namespace app\modules\admin\access\contract;

use app\modules\admin\access\domain\AdminDataScope;

/**
 * 定义运营后台授权数据的持久化边界。
 */
interface AdminAccessRepositoryInterface
{
    /**
     * 读取管理员已启用的角色及授权。
     * @param int $adminId 管理员标识
     * @return list<array<string, mixed>> 已启用角色及其权限和自定义部门
     */
    public function grantsForAdmin(int $adminId): array;

    /**
     * 读取完整部门树节点。
     * @return list<array{id: int, parent_id: int|null, name: string, sort_order: int, description: string, status: int, is_root: bool}> 部门列表
     */
    public function departments(): array;

    /**
     * 查询数据范围内的管理员分页。
     * @param AdminDataScope $scope    当前数据范围
     * @param int            $actorId  当前管理员标识
     * @param string         $keyword  搜索词
     * @param int            $page     页码
     * @param int            $pageSize 每页数量
     * @return array{data: list<array<string, mixed>>, total: int} 范围内管理员及总数
     */
    public function administrators(
        AdminDataScope $scope,
        int $actorId,
        string $keyword,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array;

    /**
     * 查询范围内单个管理员。
     * @param int            $id      目标标识
     * @param AdminDataScope $scope   当前数据范围
     * @param int            $actorId 当前管理员标识
     * @return array<string, mixed>|null 范围内管理员
     */
    public function administrator(int $id, AdminDataScope $scope, int $actorId): ?array;

    /**
     * 查询原始管理员资料，仅供已完成权限校验的内部用例使用。
     * @param int $id 目标标识
     * @return array<string, mixed>|null 不应用范围的管理员
     */
    public function rawAdministrator(int $id): ?array;

    /**
     * 读取角色列表。
     * @return list<array<string, mixed>> 角色列表
     */
    public function roles(): array;

    /**
     * 查询单个角色。
     * @param int $id 角色标识
     * @return array<string, mixed>|null 角色资料
     */
    public function role(int $id): ?array;

    /**
     * 在事务中保存角色和关系。
     * @param int|null             $id            角色标识，新增时为空
     * @param array<string, mixed> $data          角色字段
     * @param list<string>         $permissions   权限码
     * @param list<int>            $departmentIds 自定义部门标识
     * @return int 保存后的角色标识
     */
    public function saveRole(?int $id, array $data, array $permissions, array $departmentIds): int;

    /**
     * 删除未分配的角色及关系。
     * @param int $id 角色标识
     * @return void
     */
    public function deleteRole(int $id): void;

    /**
     * 在事务中保存管理员和角色关系。
     * @param int|null             $id      管理员标识，新增时为空
     * @param array<string, mixed> $data    管理员字段
     * @param list<int>            $roleIds 角色标识
     * @return int 保存后的管理员标识
     */
    public function saveAdministrator(?int $id, array $data, array $roleIds): int;

    /**
     * 删除管理员和角色关系。
     * @param int $id 管理员标识
     * @return void
     */
    public function deleteAdministrator(int $id): void;

    /**
     * 创建或修改部门节点。
     * @param int|null             $id   部门标识，新增时为空
     * @param array<string, mixed> $data 部门字段
     * @return int 保存后的部门标识
     */
    public function saveDepartment(?int $id, array $data): int;

    /**
     * 删除空部门节点。
     * @param int $id 部门标识
     * @return void
     */
    public function deleteDepartment(int $id): void;

    /**
     * 判断角色是否仍分配给管理员。
     * @param int $id 角色标识
     * @return bool 角色已分配给管理员时返回 true
     */
    public function roleIsAssigned(int $id): bool;

    /**
     * 判断部门是否存在成员、子部门或角色授权。
     * @param int $id 部门标识
     * @return bool 部门被引用时返回 true
     */
    public function departmentIsUsed(int $id): bool;

    /**
     * 判断角色标识是否被其他角色使用。
     * @param string   $code     角色标识
     * @param int|null $exceptId 排除的角色标识
     * @return bool 角色标识已被占用时返回 true
     */
    public function roleCodeExists(string $code, ?int $exceptId): bool;

    /**
     * 判断管理员账号是否被其他账号使用。
     * @param string   $username 管理员账号
     * @param int|null $exceptId 排除的管理员标识
     * @return bool 管理员账号已被占用时返回 true
     */
    public function usernameExists(string $username, ?int $exceptId): bool;

    /**
     * 判断非空工号是否已被其他系统用户使用。
     * @param string   $employeeNo 工号
     * @param int|null $exceptId   排除的管理员标识
     * @return bool 工号已被占用时返回 true
     */
    public function employeeNumberExists(string $employeeNo, ?int $exceptId): bool;
}
