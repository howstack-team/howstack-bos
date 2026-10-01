<?php

declare(strict_types=1);

namespace app\modules\admin\auth\infrastructure;

use app\modules\admin\access\infrastructure\model\AdminUserModel;
use support\Model;
use app\modules\admin\auth\contract\AdminAccountRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\exception\AdminAccountStorageException;
use Throwable;

/**
 * 使用系统数据库持久化管理员认证数据。
 */
final class DatabaseAdminAccountRepository implements AdminAccountRepositoryInterface
{
    /**
     * 按账号查询管理员。
     * @param string $username 管理员账号
     * @return AdminAccount|null 管理员账号，不存在时返回 null
     * @throws AdminAccountStorageException 数据库访问失败时抛出
     */
    public function findByUsername(string $username): ?AdminAccount
    {
        try {
            $record = AdminUserModel::query()->where('username', $username)->first();
        } catch (Throwable $exception) {
            throw new AdminAccountStorageException($exception);
        }

        return $record instanceof Model ? $this->map($record) : null;
    }

    /**
     * 按标识查询管理员。
     * @param int $adminId 管理员标识
     * @return AdminAccount|null 管理员账号，不存在时返回 null
     * @throws AdminAccountStorageException 数据库访问失败时抛出
     */
    public function findById(int $adminId): ?AdminAccount
    {
        try {
            $record = AdminUserModel::query()->where('id', $adminId)->first();
        } catch (Throwable $exception) {
            throw new AdminAccountStorageException($exception);
        }

        return $record instanceof Model ? $this->map($record) : null;
    }

    /**
     * 记录管理员最近登录时间。
     * @param int    $adminId  管理员标识
     * @param string $loggedAt 登录时间
     * @return void
     * @throws AdminAccountStorageException 数据库更新失败时抛出
     */
    public function recordSuccessfulLogin(int $adminId, string $loggedAt): void
    {
        try {
            AdminUserModel::query()->where('id', $adminId)->update([
                'last_login_at' => $loggedAt,
                'updated_at' => $loggedAt,
            ]);
        } catch (Throwable $exception) {
            throw new AdminAccountStorageException($exception);
        }
    }

    /**
     * 将数据库记录映射为管理员账号实体。
     * @param Model $record 管理员数据库记录
     * @return AdminAccount 管理员账号实体
     */
    private function map(Model $record): AdminAccount
    {
        return new AdminAccount(
            (int) $record->id,
            (string) $record->username,
            (string) $record->nickname,
            (string) $record->password_hash,
            (int) $record->status,
            isset($record->last_login_at) ? (string) $record->last_login_at : null,
            (bool) ($record->is_founder ?? false),
            isset($record->department_id) ? (int) $record->department_id : null,
        );
    }
}
