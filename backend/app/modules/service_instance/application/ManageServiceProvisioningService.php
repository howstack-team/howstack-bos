<?php

declare(strict_types=1);
namespace app\modules\service_instance\application;
use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\shared\http\ApiErrorCode;
/** 重试是单独的生产操作权限；未知结果重试仍然只查单。 */
final readonly class ManageServiceProvisioningService
{
    /** @param AdminAccessControlService $access RBAC @param ViewServiceInstancesService $services 实例查看及类型权限 @param ProvisionPaidOrderService $provisioning 交付用例 */
    public function __construct(private AdminAccessControlService $access, private ViewServiceInstancesService $services, private ProvisionPaidOrderService $provisioning) {}
    /** @param AdminAccount $actor 操作人 @param int $id 服务 @return void 人工确认未创建，仅解除履约阻塞，不自动退款 */
    public function confirmNotCreated(AdminAccount $actor, int $id, string $reason, string $reference): void
    {
        $this->access->requirePermission($actor, 'admin.services.cloud-servers.resolve');
        $service = $this->services->detail($actor, $id);
        if ($service['product_type'] !== 'cloud_server') throw new BusinessException('不支持该实例类型', ApiErrorCode::CONFLICT, 409);
        if (mb_strlen(trim($reason)) < 5 || trim($reference) === '') {
            throw new BusinessException('请填写核查说明及真实核查凭证编号', ApiErrorCode::CONFLICT, 409);
        }
        $this->provisioning->confirmNotCreated($id, $actor->id, trim($reason), trim($reference));
    }
    /** @param AdminAccount $actor 操作人 @param int $id 服务 @return void 排入重试，HTTP 不调用供应商 */
    public function retry(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.services.cloud-servers.provision');
        $service = $this->services->detail($actor, $id);
        if ($service['product_type'] !== 'cloud_server') throw new BusinessException('该服务尚未支持自动开通', ApiErrorCode::CONFLICT, 409);
        $this->provisioning->retry($id);
    }
}
