<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\modules\member\infrastructure\model\MemberModel;
use support\Model;
use app\modules\member\contract\MemberAuthRepositoryInterface;
use app\modules\member\domain\MemberAccount;

final class DatabaseMemberAuthRepository implements MemberAuthRepositoryInterface
{
    public function findByMobile(string $mobile): ?MemberAccount
    {
        return $this->map(MemberModel::query()->where('mobile', $mobile)
            ->first(['id', 'public_id', 'mobile', 'email', 'nickname', 'password_hash', 'status']));
    }

    public function findByEmail(string $email): ?MemberAccount
    {
        return $this->map(MemberModel::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first());
    }

    public function findByPublicId(string $publicId): ?MemberAccount
    {
        return $this->map(MemberModel::query()->where('public_id', $publicId)->first());
    }

    public function findById(int $id): ?MemberAccount
    {
        return $this->map(MemberModel::query()->where('id', $id)
            ->first(['id', 'public_id', 'mobile', 'email', 'nickname', 'password_hash', 'status']));
    }

    public function recordLogin(int $id): void
    {
        MemberModel::query()->where('id', $id)->update(['last_login_at' => date('Y-m-d H:i:s')]);
    }

    private function map(mixed $row): ?MemberAccount
    {
        return $row instanceof Model
            ? new MemberAccount((int) $row->id, $row->mobile, $row->nickname, $row->password_hash, (int) $row->status, $row->public_id, $row->email)
            : null;
    }
}
