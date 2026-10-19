<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\contract\ComputeResourceRepositoryInterface;
use app\modules\compute\domain\ComputeResources;
use app\modules\product\contract\ProductRepositoryInterface;
use app\modules\product\contract\ProductTypeRegistryInterface;
use app\modules\compute\contract\CloudDeliveryRuleRepositoryInterface;
use app\modules\provider\contract\ProviderBindingResolverInterface;
use app\modules\provider\contract\ProviderRepositoryInterface;
use app\modules\provider\contract\ProviderProvisioningPreviewInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

/** 后台产品配置、预览与发布编排；不创建订单或触发生产。 */
final readonly class ManageCloudProductsService
{
    public function __construct(private AdminAccessControlService $access, private ProductRepositoryInterface $products,
        private ProductTypeRegistryInterface $types, private ComputeResourceRepositoryInterface $resources,
        private ProviderBindingResolverInterface $bindings, private ProviderRepositoryInterface $connections,
        private TransactionManagerInterface $transactions, private CloudDeliveryRuleRepositoryInterface $deliveryRules, private ProviderProvisioningPreviewInterface $provisioning) {}

    /**
     * @param AdminAccount $actor 操作人 @param string $keyword 关键词 @param int $page 页码 @param int $size 条数 @return array<string, mixed> 分页
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        AdminAccount $actor,
        string $keyword,
        int $page,
        int $size,
        array $filters = [],
    ): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.view');
        return $this->products->paginate($keyword, $page, $size, $filters);
    }

    /** @param AdminAccount $actor 操作人 @return array<string, mixed> 配置引用数据 */
    public function references(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.view');
        $resources = array_intersect_key($this->allResources(), array_flip(ComputeResources::EDITABLE_KINDS));
        $connections = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'name', 'status', 'tested_at', 'capabilities', 'adapter_available', 'adapter_accepting', 'requires_retest'])), $this->connections->list());
        return ['resources' => $resources, 'connections' => $connections, 'delivery_rules' => $this->deliveryRules->all(), 'type_version' => $this->types->get('cloud_server')->version()];
    }

    /** @param AdminAccount $actor 操作人 @param int $id 方案 @return array<string, mixed> 详情 */
    public function detail(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.view');
        return $this->require($id);
    }

    /** @param AdminAccount $actor 操作人 @param int|null $id 方案 @param array<string, mixed> $data 草稿 @return array<string, mixed> 保存结果 */
    public function save(AdminAccount $actor, ?int $id, array $data): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.' . ($id === null ? 'create' : 'update'));
        return $this->products->save($id, $data);
    }

    /** @param AdminAccount $actor 操作人 @param int $id 方案 @param int $revision 草稿版本 @return array<string, mixed> 发布结果 */
    public function publish(AdminAccount $actor, int $id, int $revision): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.publish');
        return $this->storeCurrentVersion($actor, $id, $revision);
    }

    /** 社区工作台保存后自动生效，保留订单引用的不可变历史版本。
     * @return array<string, mixed> 生效配置；停用的既有配置保持停用
     */
    public function applySavedConfiguration(AdminAccount $actor, int $id, int $revision, bool $created): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.' . ($created ? 'create' : 'update'));
        return $this->transactions->run(function () use ($actor, $id, $revision, $created): array {
            $previous = $this->require($id);
            if ($created && ($previous['revision'] !== 1 || $previous['published_version_id'] !== null)) {
                throw new BusinessException('新增权限不能修改既有配置', ApiErrorCode::FORBIDDEN, 403);
            }
            $applied = $this->storeCurrentVersion($actor, $id, $revision);
            return $previous['published_version_id'] === null && $applied['status'] === 0
                ? $this->products->setStatus($id, $applied['revision'], 1)
                : $applied;
        });
    }

    /** @return array<string, mixed> 校验组合与供应对应后记录生效版本，不请求供应商生产。 */
    private function storeCurrentVersion(AdminAccount $actor, int $id, int $revision): array
    {
        return $this->transactions->run(function () use ($actor, $id, $revision): array {
            $offer = $this->require($id);
            if ($offer['revision'] !== $revision) throw new BusinessException('草稿已变化，请刷新后重试', ApiErrorCode::CONFLICT, 409);
            $type = $this->types->get('cloud_server');
            $product = $this->products->fixedProduct();
            $snapshot = $type->snapshot($offer['draft'], $this->allResources()) + ['product_name' => $product['name'], 'product_id' => $product['id'], 'offer_name' => $offer['name'], 'offer_code' => $offer['code']];
            $binding = $this->deliveryBinding($offer['draft'], $snapshot['resources'], true);
            $snapshot['source_draft_hash'] = hash('sha256', json_encode($offer['draft'], JSON_THROW_ON_ERROR));
            if (!empty($offer['draft']['delivery_rule_id']) && $this->usesLegacyRule($offer['draft'], $snapshot['resources'])) {
                $rule = $this->deliveryRules->lock($offer['draft']['delivery_rule_id']);
                if ($rule !== null) {
                    $snapshot['delivery_rule'] = array_intersect_key($rule, array_flip(['id', 'name', 'connection_id', 'line_ids', 'resource_ids', 'priority', 'revision', 'parameter_mapping']));
                }
            }
            $snapshot['delivery_binding'] = $binding;
            return $this->products->publish($id, $revision, $snapshot['schema_version'] ?? $type->version(), $snapshot, $binding, $actor->id);
        });
    }

    /** @param AdminAccount $actor 操作人 @param int $id 方案 @param int $revision 草稿版本 @param int $status 启停 @return array<string, mixed> 更新结果 */
    public function status(AdminAccount $actor, int $id, int $revision, int $status): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.update');
        return $this->products->setStatus($id, $revision, $status);
    }

    /** 发布并启用作为一次原子操作；两项权限均必需，失败不留下部分发布结果。
     * @return array<string, mixed> 发布后配置；不代表下单及真实开通已接入
     */
    public function publishAndEnable(AdminAccount $actor, int $id, int $revision): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.publish');
        $this->access->requirePermission($actor, 'admin.cloud-products.update');
        return $this->transactions->run(function () use ($actor, $id, $revision): array {
            $published = $this->publish($actor, $id, $revision);
            return $published['status'] === 1
                ? $published
                : $this->status($actor, $id, $published['revision'], 1);
        });
    }

    /** @param AdminAccount $actor 操作人 @param int $id 方案 @return list<array<string, mixed>> 历史版本 */
    public function versions(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.view');
        $this->require($id);
        return $this->products->versions($id);
    }

    /** @param AdminAccount $actor 操作人 @param int $id 方案 @param array<string, mixed> $selection 当前配置 @param bool $price 是否完整计价 @param int|null $versionId 历史版本 @return array<string, mixed> 联动或报价预览 */
    public function preview(AdminAccount $actor, int $id, array $selection, bool $price, ?int $versionId = null): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.preview');
        $offer = $this->require($id);
        $type = $this->types->get('cloud_server');
        $snapshot = $this->previewSnapshot($offer, $versionId, $type);
        return $price ? $type->price($snapshot, $selection) : $type->resolve($snapshot, $selection);
    }

    /** 校验完整购买配置及供应对应关系，返回交付参数；不发送生产请求。
     * @param AdminAccount $actor 操作人
     * @param int $id 销售方案
     * @param array<string, mixed> $selection 当前选择
     * @param int|null $versionId 发布版本
     * @return array<string, mixed> 交付参数预览
     */
    public function deliveryPreview(AdminAccount $actor, int $id, array $selection, ?int $versionId = null): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-products.preview');
        $offer = $this->require($id);
        $type = $this->types->get('cloud_server');
        $snapshot = $this->previewSnapshot($offer, $versionId, $type);
        if ($versionId !== null) {
            $binding = $snapshot['delivery_binding'] ?? null;
            if (!$binding) {
                throw new BusinessException('该历史版本未保存交付预览资料，请在新版本中预览', ApiErrorCode::CONFLICT, 409);
            }
        } else {
            $binding = $this->deliveryBinding($offer['draft'], $snapshot['resources'], false);
        }
        return $this->provisioning->build($binding, $type->provisioningConfiguration($snapshot, $selection));
    }

    /** 报价和交付预览共用相同版本归属、兼容检查及资源快照，历史读取不回查当前资源。
     * @param array<string, mixed> $offer 当前配置
     * @return array<string, mixed> 已校验快照
     */
    private function previewSnapshot(array $offer, ?int $versionId, \app\modules\product\contract\ProductTypeHandlerInterface $type): array
    {
        if ($versionId === null) {
            return $type->snapshot($offer['draft'], $this->allResources());
        }
        foreach ($this->products->versions($offer['id']) as $version) {
            if ($version['id'] !== $versionId) {
                continue;
            }
            if (!$type->supportsVersion($version['type_version'])) {
                throw new BusinessException('当前产品处理器不支持该历史配置版本', ApiErrorCode::CONFLICT, 409);
            }
            return $version['snapshot'];
        }
        throw new BusinessException('销售版本不存在或不属于当前产品', ApiErrorCode::NOT_FOUND, 404);
    }

    /**
     * 按部署位置校验并封存供应绑定；无分区配置的历史方案保持原来的单连接语义。
     * @param array<string, mixed> $draft 已验证草稿
     * @param array<string, list<array<string, mixed>>> $resources 已校验可售资源
     * @return array<string, mixed> 完整供应绑定，不包含凭据
     */
    private function deliveryBinding(array $draft, array $resources, bool $lock): array
    {
        $rule = null;
        if (!empty($draft['delivery_rule_id']) && $this->usesLegacyRule($draft, $resources)) {
            $rule = $lock ? $this->deliveryRules->lock($draft['delivery_rule_id']) : $this->deliveryRules->find($draft['delivery_rule_id']);
            if (!$rule || $rule['status'] !== 1) {
                throw new BusinessException('原有供应配置不存在或已停用', ApiErrorCode::VALIDATION_FAILED, 422);
            }
        }
        $zones = $draft['zone_provider_bindings'] ?? [];
        if ($zones === []) {
            return $this->resolveBinding($resources, $draft['provider_connection_id'], $rule);
        }
        $selected = array_column($zones, null, 'zone_id');
        $bindings = [];
        foreach ($resources['zones'] as $zone) {
            $context = $resources;
            $context['zones'] = [$zone];
            $context['regions'] = array_values(array_filter($resources['regions'], static fn (array $row): bool => $row['id'] === $zone['region_id']));
            foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) {
                $context[$kind] = array_values(array_filter($resources[$kind], static fn (array $row): bool => in_array($zone['id'], $row['zone_ids'] ?? [$row['zone_id']], true)));
            }
            foreach (['flavor_groups' => ['flavors', 'group_id'], 'image_types' => ['images', 'type_id']] as $kind => [$child, $field]) {
                $context[$kind] = array_values(array_filter($resources[$kind], static fn (array $row): bool => in_array($row['id'], array_column($context[$child], $field), true)));
            }
            $context['protections'] = array_values(array_filter($resources['protections'], static fn (array $row): bool => array_intersect($row['line_ids'] ?? [$row['line_id']], array_column($context['lines'], 'id')) !== []));
            $config = $selected[$zone['id']] ?? null;
            if ($config !== null) {
                $binding = $this->resolveBinding($context, $config['connection_id'], null);
                $binding['parameter_mapping'] = $config['parameter_mapping'];
            } else {
                $binding = $this->resolveBinding($context, $draft['provider_connection_id'], $rule);
            }
            $bindings[$zone['id']] = $binding;
        }
        // 顶层连接保留历史存储的关联键；实际交付按已封存可用区绑定选择。
        return reset($bindings) + ['zone_bindings' => $bindings];
    }

    /** @param array<string, mixed> $draft 草稿 @param array<string, list<array<string, mixed>>> $resources 可售资源 @return bool 是否仍需引用原统一供应规则 */
    private function usesLegacyRule(array $draft, array $resources): bool
    {
        $configured = array_column($draft['zone_provider_bindings'] ?? [], 'zone_id');
        return array_diff(array_column($resources['zones'], 'id'), $configured) !== [];
    }

    /** @param array<string, list<array<string, mixed>>> $resources 部署资源 @param array<string, mixed>|null $rule 历史规则 @return array<string, mixed> 经供应插件校验的绑定 */
    private function resolveBinding(array $resources, int $connectionId, ?array $rule): array
    {
        if ($rule !== null) {
            foreach (array_replace($rule['resource_ids'] ?? [], ['lines' => $rule['line_ids']]) as $kind => $ids) {
                if ($ids !== [] && array_diff(array_column($resources[$kind] ?? [], 'id'), $ids) !== []) {
                    throw new BusinessException('配置超出原有供应配置的资源范围', ApiErrorCode::VALIDATION_FAILED, 422);
                }
            }
            $connectionId = $rule['connection_id'];
        }
        $binding = $this->bindings->resolve($connectionId, $resources);
        $binding['parameter_mapping'] = $rule['parameter_mapping'] ?? [];
        return $binding;
    }

    /** @return array<string, list<array<string, mixed>>> 当前配置资源 */
    private function allResources(): array
    {
        $result = [];
        foreach (ComputeResources::KINDS as $kind) $result[$kind] = $this->resources->list($kind);
        return $result;
    }

    /** @param int $id 方案 @return array<string, mixed> 存在的方案 */
    private function require(int $id): array
    {
        return $this->products->find($id) ?? throw new BusinessException('云服务器销售方案不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
