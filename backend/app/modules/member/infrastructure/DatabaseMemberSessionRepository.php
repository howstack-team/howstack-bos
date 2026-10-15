<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\modules\member\contract\MemberSessionRepositoryInterface;
use app\modules\member\infrastructure\model\MemberSessionModel;
use app\modules\member\infrastructure\model\MemberModel;
use support\Db;

/** 会话许可持久化；密码版本与当前会员行在同一事务中核对。 */
final class DatabaseMemberSessionRepository implements MemberSessionRepositoryInterface
{
    public function create(string $id, int $memberId, string $passwordVersion, int $expiresAt): void
    {
        Db::transaction(function () use ($id, $memberId, $passwordVersion, $expiresAt): void {
            $member = MemberModel::query()->whereKey($memberId)->lockForUpdate()->first(['id', 'password_hash', 'status']);
            if ($member === null || (int) $member->status !== 1
                || !hash_equals(hash('sha256', $member->password_hash), $passwordVersion)) {
                throw new \app\exception\BusinessException('账号状态已变更，请重新登录', \app\shared\http\ApiErrorCode::UNAUTHENTICATED, 401);
            }
            MemberSessionModel::query()->where('member_id', $memberId)->where('expires_at', '<=', date('Y-m-d H:i:s'))->delete();
            MemberSessionModel::query()->create([
                'session_hash' => hash('sha256', $id), 'member_id' => $memberId,
                'password_version' => $passwordVersion, 'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    public function active(string $id, string $publicId, string $passwordVersion): bool
    {
        $memberId = MemberModel::query()->where('public_id', $publicId)->where('status', 1)->value('id');
        return $memberId !== null && MemberSessionModel::query()->where('member_id', $memberId)
            ->where('session_hash', hash('sha256', $id))->where('password_version', $passwordVersion)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))->exists();
    }

    public function revoke(string $id): void
    {
        MemberSessionModel::query()->where('session_hash', hash('sha256', $id))->delete();
    }
}
