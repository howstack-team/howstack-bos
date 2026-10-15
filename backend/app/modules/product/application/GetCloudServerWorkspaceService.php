<?php

declare(strict_types=1);

namespace app\modules\product\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\contract\ComputeResourceRepositoryInterface;
use app\modules\compute\domain\ComputeResources;
use app\modules\product\contract\{ProductRepositoryInterface, CloudDeliveryRuleRepositoryInterface};

/** 概览只统计配置数据，不把只读配置误当作已运行资源。 */
final readonly class GetCloudServerWorkspaceService
{
    public function __construct(private AdminAccessControlService $access, private ProductRepositoryInterface $products,
        private ComputeResourceRepositoryInterface $resources, private CloudDeliveryRuleRepositoryInterface $rules) {}

    /** @param AdminAccount $actor 操作人 @return array<string, mixed> 配置统计。 */
    public function get(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-servers.view');
        $permissions = $this->access->permissions($actor);
        $resources = [];
        if (in_array('admin.compute-resources.view', $permissions, true)) {
            foreach (ComputeResources::KINDS as $kind) {
                $rows = $this->resources->list($kind);
                $resources[$kind] = ['total' => count($rows), 'enabled' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 1))];
            }
        }
        return ['product' => $this->products->fixedProduct(), 'resources' => $resources,
            'offers' => in_array('admin.cloud-products.view', $permissions, true) ? $this->products->overview() : null,
            'delivery_rules' => in_array('admin.cloud-delivery-rules.view', $permissions, true) ? count($this->rules->all()) : null];
    }
}
