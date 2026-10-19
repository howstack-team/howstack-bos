<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;

/** 后台三组操作分别核权，读实例和写操作均由服务端检查。 */
final readonly class ManageInstanceOperationsService
{
    /**
     * @param AdminAccessControlService $access RBAC
     * @param ViewServiceInstancesService $services 类型查看权限
     * @param OperateInstanceService $operations 运维门面
     */
    public function __construct(private AdminAccessControlService $access, private ViewServiceInstancesService $services, private OperateInstanceService $operations)
    {
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 服务
     * @return array<string,mixed> 仅展示已授权动作
     */
    public function options(AdminAccount $actor, int $id): array
    {
        $this->services->detail($actor, $id);
        $result = $this->operations->options(null, $id);
        $result['actions'] = array_values(array_filter($result['actions'], function (string $action) use ($actor): bool {
            try {
                $this->access->requirePermission($actor, $this->permission($action));
                return true;
            } catch (\app\exception\BusinessException) {
                return false;
            }
        }));
        try {
            $this->access->requirePermission($actor, 'admin.services.cloud-servers.vnc');
        } catch (\app\exception\BusinessException) {
            $result['vnc_available'] = false;
        }
        if (!in_array('reinstall', $result['actions'], true)) {
            $result['images'] = [];
        }
        return $result;
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 服务
     * @param array<string,mixed> $input 核定输入
     * @return array{id:int,status:string} 受理任务
     */
    public function submit(AdminAccount $actor, int $id, #[\SensitiveParameter] array $input): array
    {
        $this->access->requirePermission($actor, $this->permission($input['action']));
        $this->services->detail($actor, $id);
        return $this->operations->submit(null, $id, $input, 'admin:' . $actor->id);
    }

    /**
     * @param string $action 标准动作
     * @return string 固定操作权限
     */
    private function permission(string $action): string
    {
        return 'admin.services.cloud-servers.' . match ($action) {
            'reinstall' => 'reinstall', 'reset_password' => 'reset-password', default => 'power',
        };
    }
}
