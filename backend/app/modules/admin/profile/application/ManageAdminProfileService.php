<?php

declare(strict_types=1);

namespace app\modules\admin\profile\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\modules\admin\profile\contract\AdminProfileRepositoryInterface;

/** 个人设置始终使用认证账户标识，不接受客户端指定目标管理员。 */
final readonly class ManageAdminProfileService
{
    public function __construct(
        private AdminProfileRepositoryInterface $profiles,
        private AdminAccessControlService $access,
    ) {
    }

    /** @return array<string, int|string|null> 公开个人资料 */
    public function profile(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.profile.view');

        return $this->profiles->find($actor->id);
    }

    /** @param array{employee_no: ?string, mobile: ?string, email: ?string} $data */
    public function update(AdminAccount $actor, array $data): array
    {
        $this->access->requirePermission($actor, 'admin.profile.update');
        $this->profiles->update($actor->id, $data);

        return $this->profiles->find($actor->id);
    }

    /** 原密码验证成功后原子修改；旧哈希绑定的令牌随即失效。 */
    public function changePassword(AdminAccount $actor, string $currentPassword, string $newPassword): void
    {
        $this->access->requirePermission($actor, 'admin.profile.password');
        if (!password_verify($currentPassword, $actor->passwordHash)) {
            throw new BusinessException('原密码不正确');
        }
        if (password_verify($newPassword, $actor->passwordHash)) {
            throw new BusinessException('新密码不能与原密码相同');
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!$this->profiles->replacePassword($actor->id, $actor->passwordHash, $hash)) {
            throw AdminAuthenticationException::invalidToken();
        }
    }
}
