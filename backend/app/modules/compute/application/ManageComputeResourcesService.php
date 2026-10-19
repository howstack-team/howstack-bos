<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\contract\ComputeResourceRepositoryInterface;
use app\modules\compute\domain\ComputeResources;
use app\shared\http\ApiErrorCode;

/** 配置资源管理的权限及关联约束。停用不改变已发布快照。 */
final readonly class ManageComputeResourcesService
{
    public function __construct(private AdminAccessControlService $access, private ComputeResourceRepositoryInterface $resources) {}

    /** @param AdminAccount $actor 操作人 @return array<string, list<array<string, mixed>>> 类型专属资源 */
    public function all(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.compute-resources.view');
        $result = [];
        foreach (ComputeResources::EDITABLE_KINDS as $kind) $result[$kind] = $this->resources->list($kind);
        return $result;
    }

    /** @param AdminAccount $actor 操作人 @param string $kind 种类 @param int|null $id 标识 @param array<string, mixed> $data 已验证字段 @return array<string, mixed> 保存结果 */
    public function save(AdminAccount $actor, string $kind, ?int $id, array $data): array
    {
        $this->access->requirePermission($actor, 'admin.compute-resources.' . ($id === null ? 'create' : 'update'));
        if (!in_array($kind, ComputeResources::EDITABLE_KINDS, true)) {
            throw new BusinessException('资源类型已停止维护，请在线路中设置网络规则', ApiErrorCode::NOT_FOUND, 404);
        }
        foreach (['region_id' => 'regions', 'zone_id' => 'zones', 'line_id' => 'lines', 'group_id' => 'flavor_groups', 'type_id' => 'image_types'] as $field => $parent) {
            if (isset($data[$field]) && $this->resources->find($parent, (int) $data[$field]) === null) {
                throw new BusinessException('关联配置资源不存在', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => ['请选择有效的关联资源']]]);
            }
        }
        foreach (['zone_ids' => 'zones', 'line_ids' => 'lines'] as $field => $parent) foreach ($data[$field] ?? [] as $parentId) {
            if ($this->resources->find($parent, $parentId) === null) throw new BusinessException('关联配置资源不存在', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => ['请选择有效的关联资源']]]);
        }
        return $this->resources->save($kind, $id, $data);
    }
}
