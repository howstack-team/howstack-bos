<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\member\contract\RealNameAuthenticationRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 编排运营后台实名认证查询和审核。 */
final readonly class RealNameManagementService
{
    public function __construct(private RealNameAuthenticationRepositoryInterface $records, private AdminAccessControlService $access) {}

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function paginate(AdminAccount $actor, string $keyword, ?string $type, ?string $status, int $page, int $pageSize): array
    {
        $this->access->requirePermission($actor, 'admin.real-name-authentications.view');
        return $this->records->paginate($keyword, $type, $status, $page, $pageSize);
    }

    /** @return array<string, mixed> */
    public function detail(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.real-name-authentications.view');
        return $this->records->find($id) ?? throw new BusinessException('实名认证记录不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    public function review(AdminAccount $actor, int $id, string $decision, string $reason): array
    {
        $this->access->requirePermission($actor, 'admin.real-name-authentications.review');
        if ($decision === 'rejected' && $reason === '') throw new BusinessException('拒绝认证时必须填写原因', ApiErrorCode::VALIDATION_FAILED, 422);
        if ($this->records->find($id) === null) throw new BusinessException('实名认证记录不存在', ApiErrorCode::NOT_FOUND, 404);
        if (!$this->records->reviewPending($id, $decision, $actor->id, $decision === 'rejected' ? $reason : '')) {
            throw new BusinessException('该实名认证申请已被处理，请刷新后重试', ApiErrorCode::CONFLICT, 409);
        }
        return $this->records->find($id) ?? throw new BusinessException('实名认证记录不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
