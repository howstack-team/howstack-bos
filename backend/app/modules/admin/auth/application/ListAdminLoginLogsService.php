<?php

declare(strict_types=1);

namespace app\modules\admin\auth\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;

/** 授权后分页查询管理员登录日志。 */
final readonly class ListAdminLoginLogsService
{
    public function __construct(private AdminAccessControlService $access, private LoginLogRepositoryInterface $logs)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function execute(
        AdminAccount $actor,
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $this->access->requirePermission($actor, 'admin.page.login-logs.view');

        return $this->logs->paginate($keyword, $status, $page, $pageSize, $filters);
    }
}
