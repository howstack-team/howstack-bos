<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;

/** 后台续费与任务重试分别核权；创建订单不代表替会员扣款。 */
final readonly class ManageServiceRenewalService
{
    /**
     * @param AdminAccessControlService $access 权限
     * @param ViewServiceInstancesService $services 实例类型权限
     * @param RenewServiceService $renewals 续费
     * @param ProcessServiceLifecycleService $lifecycle 任务
     */
    public function __construct(private AdminAccessControlService $access, private ViewServiceInstancesService $services,
        private RenewServiceService $renewals, private ProcessServiceLifecycleService $lifecycle)
    {
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 实例
     * @param int $months 期数
     * @return array<string,mixed> 报价
     */
    public function quote(AdminAccount $actor, int $id, int $months): array
    {
        $this->access->requirePermission($actor, 'admin.services.cloud-servers.renew');
        $this->services->detail($actor, $id);
        return $this->renewals->quote(null, $id, $months);
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 实例
     * @param array<string,mixed> $input 请求
     * @return array{id:int} 原会员的待付款订单
     */
    public function create(AdminAccount $actor, int $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.services.cloud-servers.renew');
        $this->services->detail($actor, $id);
        return $this->renewals->create(null, $id, $input, 'admin:' . $actor->id);
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param int $id 实例
     * @return void 只恢复任务，未知状态只查单
     */
    public function retry(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.services.cloud-servers.lifecycle');
        $this->services->detail($actor, $id);
        $this->lifecycle->retry($id);
    }
}
