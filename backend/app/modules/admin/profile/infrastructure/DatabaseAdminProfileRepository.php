<?php

declare(strict_types=1);

namespace app\modules\admin\profile\infrastructure;

use app\exception\BusinessException;
use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\modules\admin\profile\contract\AdminProfileRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/** 使用管理员模型维护个人字段，查询结果按白名单输出。 */
final class DatabaseAdminProfileRepository implements AdminProfileRepositoryInterface
{
    public function find(int $adminId): array
    {
        $row = AdminUserModel::query()->whereKey($adminId)->where('status', 1)
            ->first(['id', 'username', 'nickname', 'employee_no', 'mobile', 'email']);
        if ($row === null) {
            throw AdminAuthenticationException::invalidToken();
        }

        return [
            'id' => (int) $row->id,
            'username' => (string) $row->username,
            'nickname' => (string) $row->nickname,
            'employee_no' => $row->employee_no,
            'mobile' => $row->mobile,
            'email' => $row->email,
        ];
    }

    public function update(int $adminId, array $data): void
    {
        // 同时限制账户状态，停用后不再接受资料修改。
        try {
            AdminUserModel::query()->whereKey($adminId)->where('status', 1)->update([
                'employee_no' => $data['employee_no'],
                'mobile' => $data['mobile'],
                'email' => $data['email'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new BusinessException('工号已被其他系统用户使用');
        }
    }

    public function replacePassword(int $adminId, string $oldHash, string $newHash): bool
    {
        return AdminUserModel::query()->whereKey($adminId)->where('status', 1)
            ->where('password_hash', $oldHash)->update(['password_hash' => $newHash]) === 1;
    }
}
