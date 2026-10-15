<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\contract\{ComputeInventoryRepositoryInterface, ComputeResourceRepositoryInterface};
use app\shared\http\ApiErrorCode;

final readonly class ManageComputeInventoryService
{
    public function __construct(private AdminAccessControlService $access, private ComputeInventoryRepositoryInterface $inventory, private ComputeResourceRepositoryInterface $resources) {}

    /** @param AdminAccount $actor 操作人 @return list<array<string, mixed>> 库存 */
    public function all(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.compute-resources.view');
        return $this->inventory->all();
    }

    /** @param AdminAccount $actor 操作人 @param array<string, mixed> $input 白名单输入 @return array<string, mixed> 结果 */
    public function configure(AdminAccount $actor, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.compute-resources.update');
        $flavor = $this->resources->find('flavors', $input['flavor_id']);
        if (!$flavor || !in_array($input['zone_id'], $flavor['zone_ids'], true)) throw new BusinessException('规格不属于所选可用区', ApiErrorCode::VALIDATION_FAILED, 422);
        return $this->inventory->configure($input['zone_id'], $input['flavor_id'], $input['capacity'], $input['revision']);
    }
}
