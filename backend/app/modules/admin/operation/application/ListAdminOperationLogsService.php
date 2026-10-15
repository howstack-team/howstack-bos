<?php

declare(strict_types=1);

namespace app\modules\admin\operation\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\operation\contract\OperationLogRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 授权后查询管理员操作日志摘要和详情。 */
final readonly class ListAdminOperationLogsService
{
    public function __construct(private AdminAccessControlService $access, private OperationLogRepositoryInterface $logs)
    {
    }

    /**
     * @return array{data: list<array<string, int|string>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        AdminAccount $actor,
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $this->access->requirePermission($actor, 'admin.page.operation-logs.view');

        return $this->logs->paginate($keyword, $status, $page, $pageSize, $filters);
    }

    /** @return array<string, int|string|null> */
    public function detail(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.page.operation-logs.view');

        return $this->logs->find($id)
            ?? throw new BusinessException('操作日志不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
