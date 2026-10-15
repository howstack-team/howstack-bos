<?php

declare(strict_types=1);
namespace app\modules\admin\auth\infrastructure;
use app\modules\admin\auth\contract\AdminMfaRepositoryInterface;
use app\modules\admin\auth\infrastructure\model\{AdminMfaCredentialModel, AdminMfaChallengeModel};
use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use support\Db;
/** 所有验证和恢复码消费均在数据库行锁内完成。 */
final readonly class DatabaseAdminMfaRepository implements AdminMfaRepositoryInterface
{
    public function locked(int $adminId, callable $handler): mixed
    {
        return Db::transaction(function () use ($adminId, $handler) {
            $user = AdminUserModel::query()->whereKey($adminId)->lockForUpdate()->first();
            if ($user === null || (int) $user->status !== 1) throw AdminAuthenticationException::invalidToken();
            $now = date('Y-m-d H:i:s');
            $state = AdminMfaCredentialModel::query()->firstOrCreate(['admin_user_id' => $adminId], ['recovery_hashes' => '[]', 'created_at' => $now, 'updated_at' => $now]);
            return $handler($state->refresh()->getAttributes());
        });
    }
    public function find(int $adminId): ?array { return AdminMfaCredentialModel::query()->find($adminId)?->getAttributes(); }
    public function save(int $adminId, array $values, bool $rotate = false): void
    {
        AdminMfaCredentialModel::query()->whereKey($adminId)->update($values + ['updated_at' => date('Y-m-d H:i:s')]);
        if ($rotate) {
            AdminUserModel::query()->whereKey($adminId)->increment('auth_version');
            AdminMfaChallengeModel::query()->where('admin_user_id', $adminId)->delete();
        }
    }
    public function createChallenge(array $values): void
    {
        AdminMfaChallengeModel::query()->where('admin_user_id', $values['admin_user_id'])->delete();
        AdminMfaChallengeModel::query()->create($values);
    }
    public function challenge(string $hash): ?array { return AdminMfaChallengeModel::query()->find($hash)?->getAttributes(); }
    public function attempt(string $hash, bool $consume): void
    {
        if ($consume) AdminMfaChallengeModel::query()->whereKey($hash)->delete();
        else AdminMfaChallengeModel::query()->whereKey($hash)->increment('attempts');
    }
}
