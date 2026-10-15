<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\finance\application\FinanceMemberReferenceService;
use app\modules\member\contract\MemberRepositoryInterface;
use app\modules\member\contract\MemberCatalogRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 编排运营后台会员管理用例。 */
final readonly class MemberManagementService
{
    /**
     * 初始化会员管理用例。
     */
    public function __construct(
        private MemberRepositoryInterface $members,
        private AdminAccessControlService $access,
        private FinanceMemberReferenceService $finances,
        private ?MemberCatalogRepositoryInterface $catalog = null,
    ) {
    }

    /**
     * 分页查询会员。
     * @return array{data: list<array<string, mixed>>, total: int} 会员分页
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
        $this->access->requirePermission($actor, 'admin.members.view');

        return $this->members->paginate($keyword, $status, $page, $pageSize, $filters);
    }

    /**
     * 查询指定会员。
     * @return array<string, mixed> 会员资料
     */
    public function member(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.members.view');

        return $this->members->find($id) ?? throw $this->notFound();
    }

    /**
     * 新增或更新会员。
     * @param AdminAccount         $actor 当前管理员
     * @param int|null             $id    会员标识，新增时为空
     * @param array<string, mixed> $input 已验证的白名单字段
     * @return array<string, mixed> 保存后的会员资料
     */
    public function save(AdminAccount $actor, ?int $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.members.' . ($id === null ? 'create' : 'update'));
        $previous = $id === null ? null : $this->members->find($id);
        if ($id !== null && $previous === null) {
            throw $this->notFound();
        }
        $mobile = trim((string) ($input['mobile'] ?? '')) ?: null;
        $email = strtolower(trim((string) ($input['email'] ?? ''))) ?: null;
        $groupId = array_key_exists('group_id', $input) ? ($input['group_id'] === null ? null : (int) $input['group_id']) : ($previous['group_id'] ?? null);
        $levelId = array_key_exists('level_id', $input) ? ($input['level_id'] === null ? null : (int) $input['level_id']) : ($previous['level_id'] ?? null);
        if ($groupId !== null && $this->catalog !== null && (($group = $this->catalog->group($groupId)) === null || (int) $group['status'] !== 1)) {
            throw new BusinessException('会员分组不存在或已停用', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        if ($levelId !== null && $this->catalog !== null && (($level = $this->catalog->level($levelId)) === null || (int) $level['status'] !== 1)) {
            throw new BusinessException('会员等级不存在或已停用', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        if ($mobile !== null && $this->members->mobileExists($mobile, $id)) {
            throw new BusinessException('手机号已被其他会员使用', ApiErrorCode::CONFLICT, 409);
        }
        if ($email !== null && $this->members->emailExists($email, $id)) {
            throw new BusinessException('邮箱已被其他会员使用', ApiErrorCode::CONFLICT, 409);
        }
        $data = [
            'nickname' => trim((string) $input['nickname']),
            'group_id' => $groupId,
            'level_id' => $levelId,
            'mobile' => $mobile,
            'email' => $email,
            'description' => trim((string) ($input['description'] ?? '')),
            'status' => (int) $input['status'],
        ];
        if (!empty($input['password'])) {
            if (!is_string($input['password']) || mb_strlen($input['password']) < 8 || strlen($input['password']) > 72) {
                throw new BusinessException('请检查会员密码', ApiErrorCode::VALIDATION_FAILED, 422,
                    ['errors' => ['password' => ['密码至少8个字符，且最多72字节']]]);
            }
            $data['password_hash'] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }
        $savedId = $this->members->save($id, $data);

        return $this->members->find($savedId) ?? throw $this->notFound();
    }

    /**
     * 删除指定会员。
     * @param AdminAccount $actor 当前管理员
     * @param int          $id    会员标识
     * @return void
     */
    public function delete(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.members.delete');
        if ($this->members->find($id) === null) {
            throw $this->notFound();
        }
        if ($this->members->hasRealNameRecords($id)) {
            throw new BusinessException('该会员存在实名认证历史，不能删除；可以改为禁用账号', ApiErrorCode::CONFLICT, 409);
        }
        if ($this->members->hasOrders($id)) {
            throw new BusinessException('该会员存在订单历史，不能删除；可以改为禁用账号', ApiErrorCode::CONFLICT, 409);
        }
        if ($this->finances->hasRecords($id)) {
            throw new BusinessException('该会员存在资金或支付记录，不能删除；可以改为禁用账号', ApiErrorCode::CONFLICT, 409);
        }
        $this->members->delete($id);
    }

    private function notFound(): BusinessException
    {
        return new BusinessException('会员不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
