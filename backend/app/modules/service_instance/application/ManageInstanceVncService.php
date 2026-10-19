<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;

/** 后台查看权限与远程控制权限分开核验，续检也重新执行RBAC。 */
final readonly class ManageInstanceVncService
{
    /**
     * @param AdminAccessControlService $access 权限
     * @param ViewServiceInstancesService $services 类型权限
     * @param OpenInstanceVncService $vnc 认证VNC用例
     */
    public function __construct(private AdminAccessControlService $access, private ViewServiceInstancesService $services, private OpenInstanceVncService $vnc)
    {
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 实例
     * @return array<string,mixed> 允许访问标志
     */
    public function access(AdminAccount $actor, int $id): array
    {
        $this->authorize($actor, $id);
        return $this->vnc->access(null, $id);
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 实例
     * @return array<string,mixed> 临时连接参数
     */
    public function open(AdminAccount $actor, int $id): array
    {
        $this->authorize($actor, $id);
        $connection = $this->vnc->open(null, $id, 'admin:' . $actor->id);
        $this->authorize($actor, $id);
        return $connection;
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 实例
     * @return void 服务端检查类型与独立权限
     */
    private function authorize(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.services.cloud-servers.vnc');
        $this->services->detail($actor, $id);
    }
}
