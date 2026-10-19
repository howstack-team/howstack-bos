<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\contract\ComputeResourceRepositoryInterface;
use app\modules\compute\domain\ComputeResources;
use app\modules\compute\contract\CloudDeliveryRuleRepositoryInterface;
use app\modules\provider\contract\ProviderRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 管理外部平台交付配置；同系统采购及真实生产在后续阶段接入。 */
final readonly class ManageCloudDeliveryRulesService
{
    public function __construct(private AdminAccessControlService $access, private CloudDeliveryRuleRepositoryInterface $rules,
        private ComputeResourceRepositoryInterface $resources, private ProviderRepositoryInterface $connections) {}

    /** @param AdminAccount $actor 操作人 @return list<array<string, mixed>> 规则。 */
    public function all(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-delivery-rules.view');
        return $this->rules->all();
    }

    /** @param AdminAccount $actor 操作人 @return array<string, mixed> 有权编辑规则的安全引用。 */
    public function references(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-delivery-rules.view');
        $resources = [];
        foreach (ComputeResources::KINDS as $kind) $resources[$kind] = $this->resources->list($kind);
        return ['lines' => $resources['lines'], 'resources' => $resources, 'connections' => array_map(static fn (array $row): array =>
            array_intersect_key($row, array_flip(['id', 'name', 'status', 'adapter_available', 'adapter_accepting', 'adapter_catalogs', 'requires_retest'])), $this->connections->list())];
    }

    /** @param AdminAccount $actor 操作人 @param int|null $id 规则 @param array<string, mixed> $data 已验证输入 @return array<string, mixed> 保存结果。 */
    public function save(AdminAccount $actor, ?int $id, array $data): array
    {
        $this->access->requirePermission($actor, 'admin.cloud-delivery-rules.' . ($id === null ? 'create' : 'update'));
        $connection = $this->connections->find($data['connection_id']);
        if (!$connection) throw new BusinessException('资源供应商不存在', ApiErrorCode::VALIDATION_FAILED, 422);
        if ($data['status'] === 1 && ($connection['status'] !== 1 || !$connection['adapter_available'] || $connection['requires_retest']
            || !in_array('cloud_server', $connection['capabilities']['product_types'] ?? [], true))) {
            throw new BusinessException('启用规则需要选择已测试并启用的资源供应商', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        foreach ($data['line_ids'] as $lineId) {
            if ($this->resources->find('lines', $lineId) === null) throw new BusinessException('适用线路不存在', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        foreach ($data['resource_ids'] ?? [] as $kind => $ids) foreach ($ids as $resourceId) {
            if ($this->resources->find($kind, $resourceId) === null) throw new BusinessException('适用配置资源不存在', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['resource_ids.' . $kind => ['请选择有效的配置资源']]]);
        }
        return $this->rules->save($id, $data);
    }
}
