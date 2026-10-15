<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\modules\member\infrastructure\model\RealNameAuthenticationModel;
use app\modules\member\contract\RealNameAuthenticationRepositoryInterface;

/** 保存实名认证历史并仅向后台返回脱敏标识。 */
final class DatabaseRealNameAuthenticationRepository implements RealNameAuthenticationRepositoryInterface
{
    public function __construct(private readonly RealNameAuthenticationReadQuery $reads = new RealNameAuthenticationReadQuery())
    {
    }

    /**
     * {@inheritDoc}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $keyword,
        ?string $type,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        return $this->reads->paginate($keyword, $type, $status, $page, $pageSize, $filters);
    }

    /** {@inheritDoc} */
    public function find(int $id): ?array
    {
        return $this->reads->find($id);
    }

    /** {@inheritDoc} */
    public function hasPending(int $memberId): bool
    {
        return RealNameAuthenticationModel::query()->where('member_id', $memberId)->where('status', 'pending')->exists();
    }

    /** {@inheritDoc} */
    public function latestApproved(int $memberId, string $type): ?array
    {
        $row = RealNameAuthenticationModel::query()->where('member_id', $memberId)
            ->where('authentication_type', $type)->where('status', 'approved')->orderBy('id', 'desc')->first(['id', 'member_id', 'authentication_type', 'status', 'id_number_hash']);
        return $row?->getAttributes();
    }

    /** {@inheritDoc} */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) RealNameAuthenticationModel::query()->create($data + [
            'submitted_at' => $now,
        ])->getKey();
    }

    /** {@inheritDoc} */
    public function reviewPending(int $id, string $status, int $reviewerId, string $reason): bool
    {
        $now = date('Y-m-d H:i:s');
        return RealNameAuthenticationModel::query()->where('id', $id)->where('status', 'pending')->update([
            'status' => $status, 'reviewed_by' => $reviewerId, 'reviewed_at' => $now,
            'rejection_reason' => $reason,
        ]) === 1;
    }

}
