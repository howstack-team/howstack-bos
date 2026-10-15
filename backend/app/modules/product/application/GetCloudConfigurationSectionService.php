<?php

declare(strict_types=1);

namespace app\modules\product\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\application\{ManageComputeResourcesService, ManageComputeInventoryService};
use app\shared\http\ApiErrorCode;

/** 配置页面的读取入口：页面授权与既有数据范围同时校验，GET 不修改草稿。 */
final readonly class GetCloudConfigurationSectionService
{
    public function __construct(
        private AdminAccessControlService $access,
        private ManageCloudWorkspaceConfigurationService $configuration,
        private ManageCloudProductsService $products,
        private ManageComputeResourcesService $resources,
        private ManageComputeInventoryService $inventory,
    ) {
    }

    /** @return array<string, mixed> 共用配置、资源和库存，写操作仍使用独立业务权限。 */
    public function get(AdminAccount $actor, string $section): array
    {
        if (!in_array($section, ['deployment', 'specification', 'network', 'storage', 'purchase'], true)) {
            throw new BusinessException('配置页面不存在', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $this->access->requirePermission($actor, 'admin.cloud-configuration.' . $section . '.view');
        $this->access->requirePermission($actor, $section === 'purchase' ? 'admin.cloud-products.view' : 'admin.compute-resources.view');
        $permissions = $this->access->permissions($actor);
        $canView = in_array('admin.cloud-products.view', $permissions, true);
        $canResources = in_array('admin.compute-resources.view', $permissions, true);
        $configuration = $canView ? $this->configuration->get($actor) : ['configuration' => null, 'choices' => [], 'default_configuration_id' => null];
        return $configuration + [
            'references' => $canView ? $this->products->references($actor) : ['resources' => $this->resources->all($actor), 'connections' => [], 'delivery_rules' => []],
            'inventory' => $canResources ? $this->inventory->all($actor) : [],
        ];
    }
}
