<?php

declare(strict_types=1);

namespace app\modules\admin\profile\contract;

/** 只操作指定管理员的个人资料，不能修改角色、部门或状态。 */
interface AdminProfileRepositoryInterface
{
    /** @return array{id: int, username: string, nickname: string, employee_no: ?string, mobile: ?string, email: ?string} */
    public function find(int $adminId): array;

    /** @param array{employee_no: ?string, mobile: ?string, email: ?string} $data 已校验的资料 */
    public function update(int $adminId, array $data): void;

    /** 原子替换仍然匹配旧哈希的密码，避免并发修改覆盖。 */
    public function replacePassword(int $adminId, string $oldHash, string $newHash): bool;
}
