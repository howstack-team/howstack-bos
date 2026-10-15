<?php

declare(strict_types=1);

namespace app\modules\finance\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\finance\contract\FinanceRecordRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 财务记录查询的授权与资源边界。 */
final readonly class ViewFinanceRecordsService
{
    private const array PERMISSIONS = [
        'recharges' => 'admin.finance.recharges.view',
        'payments' => 'admin.finance.payments.view',
        'transactions' => 'admin.finance.transactions.view',
        'refunds' => 'admin.finance.refunds.view',
        'invoices' => 'admin.finance.invoices.view',
        'withdrawals' => 'admin.finance.withdrawals.view',
    ];

    public function __construct(
        private AdminAccessControlService $access,
        private FinanceRecordRepositoryInterface $records,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function paginate(
        AdminAccount $actor,
        string $resource,
        int $page,
        int $pageSize,
        ?string $status,
        array $filters = [],
    ): array
    {
        $this->authorize($actor, $resource);

        return $this->records->paginate($resource, $page, $pageSize, $status, $filters);
    }

    /** @return array<string, mixed> */
    public function detail(AdminAccount $actor, string $resource, int $id): array
    {
        $this->authorize($actor, $resource);

        return $this->records->find($resource, $id)
            ?? throw new BusinessException('财务记录不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    private function authorize(AdminAccount $actor, string $resource): void
    {
        $permission = self::PERMISSIONS[$resource]
            ?? throw new BusinessException('财务记录类型不存在', ApiErrorCode::NOT_FOUND, 404);
        $this->access->requirePermission($actor, $permission);
    }
}
