<?php

declare(strict_types=1);

namespace app\modules\official_platform\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\official_platform\contract\OfficialPlatformClientInterface;
use app\shared\http\ApiErrorCode;
use app\modules\software_maintenance\contract\{InstallationJobRepositoryInterface, InstallationLockInterface};

/** 本机RBAC先于官网激活和解绑执行，授权归属由官网核验。 */
final readonly class ManageOfficialConnectionService
{
    /**
     * @param AdminAccessControlService $access 本机权限
     * @param OfficialPlatformClientInterface $client 固定官网端口
     */
    public function __construct(
        private AdminAccessControlService $access,
        private OfficialPlatformClientInterface $client,
        private InstallationJobRepositoryInterface $jobs,
        private InstallationLockInterface $lock,
    ) {
    }

    /**
     * @param AdminAccount $actor 当前管理员
     * @return array<string,mixed> 安全连接状态
     */
    public function status(AdminAccount $actor): array
    {
        $permissions = $this->access->permissions($actor);
        if (array_intersect($permissions, ['admin.license.view', 'admin.app-center.view', 'admin.updates.view']) === []) {
            throw new BusinessException('无权查看官网连接', ApiErrorCode::FORBIDDEN, 403);
        }
        return $this->client->localStatus();
    }

    /** @param AdminAccount $actor 管理员 @param string $code 授权码 @return array<string,mixed> 激活上下文 */
    public function activate(AdminAccount $actor, string $code): array
    {
        $this->access->requirePermission($actor, 'admin.platform.connect');
        $this->access->requirePermission($actor, 'admin.license.manage');
        return $this->lock->synchronized(function () use ($code): array {
            $this->guardInstallation();
            return $this->client->activate($code);
        });
    }

    /**
     * @param AdminAccount $actor 管理员
     * @return array<string,mixed> 实时安全状态
     */
    public function refresh(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.platform.connect');
        $this->client->synchronize();
        return $this->client->localStatus();
    }

    /**
     * @param AdminAccount $actor 管理员
     * @return void 官网撤销连接，不清除证书、插件或业务数据
     */
    public function disconnect(AdminAccount $actor): void
    {
        $this->access->requirePermission($actor, 'admin.platform.connect');
        $this->access->requirePermission($actor, 'admin.license.manage');
        $this->lock->synchronized(function (): void {
            $this->guardInstallation();
            $this->client->disconnect();
        });
    }
    /** @return void 活跃任务绑定现有连接，禁止中途更换身份 */
    private function guardInstallation(): void
    {
        if ($this->jobs->active() !== null) {
            throw new BusinessException('安装或更新任务结束后才能更换官网连接', ApiErrorCode::CONFLICT, 409);
        }
    }
}
