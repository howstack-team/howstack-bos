<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\service_instance\contract\ServiceInstanceRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 按产品类型授权查询已创建的服务实例。 */
final readonly class ViewServiceInstancesService
{
    private const array PERMISSIONS = [
        'cloud_server' => 'admin.services.cloud-servers.view',
        'physical_server' => 'admin.services.physical-servers.view',
        'server_colocation' => 'admin.services.server-colocations.view',
    ];

    public function __construct(
        private AdminAccessControlService $access,
        private ServiceInstanceRepositoryInterface $instances,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function paginate(AdminAccount $actor, string $productType, string $keyword, ?string $status, int $page, int $pageSize): array
    {
        $this->authorize($actor, $productType);

        return $this->instances->paginate($productType, $keyword, $status, $page, $pageSize);
    }

    /** @return array<string, mixed> */
    public function detail(AdminAccount $actor, int $id): array
    {
        $instance = $this->instances->find($id)
            ?? throw new BusinessException('服务实例不存在', ApiErrorCode::NOT_FOUND, 404);
        $this->authorize($actor, (string) $instance['product_type']);

        return $instance;
    }

    private function authorize(AdminAccount $actor, string $productType): void
    {
        $permission = self::PERMISSIONS[$productType]
            ?? throw new BusinessException('服务类型不存在', ApiErrorCode::NOT_FOUND, 404);
        $this->access->requirePermission($actor, $permission);
    }
}
