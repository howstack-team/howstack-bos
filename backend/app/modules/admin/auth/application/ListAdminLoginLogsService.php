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

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function execute(AdminAccount $actor, string $keyword, ?int $status, int $page, int $pageSize): array
    {
        $this->access->requirePermission($actor, 'admin.page.login-logs.view');

        return $this->logs->paginate($keyword, $status, $page, $pageSize);
    }
}
