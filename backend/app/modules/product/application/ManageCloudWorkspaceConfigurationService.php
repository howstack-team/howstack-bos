<?php

declare(strict_types=1);

namespace app\modules\product\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\product\contract\ProductRepositoryInterface;

/** 固定云服务器配置入口；读取无写入，首次保存及切换受产品锁和独立权限保护。 */
final readonly class ManageCloudWorkspaceConfigurationService
{
    public function __construct(
        private AdminAccessControlService $access,
        private ProductRepositoryInterface $products,
    ) {
    }

    /** @return array<string, mixed> 当前配置与未删除的历史选择 */
    public function get(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.view');
        return $this->products->workspaceConfiguration();
    }

    /** @param array<string, mixed> $data 白名单草稿 @return array<string, mixed> 保存结果 */
    public function save(AdminAccount $actor, ?int $id, array $data, ?int $expectedId): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.' . ($id === null ? 'create' : 'update'));
        return $this->products->saveWorkspaceConfiguration($id, $data, $expectedId);
    }
}
