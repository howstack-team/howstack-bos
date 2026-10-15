<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\modules\member\contract\MemberAuthChallengeRepositoryInterface;
use app\modules\member\domain\MemberAccount;
use app\modules\member\infrastructure\model\{MemberAuthChallengeModel, MemberModel, MemberSessionModel};
use support\Db;

final class DatabaseMemberAuthChallengeRepository implements MemberAuthChallengeRepositoryInterface
{
    public function issue(array $challenge): void
    {
        Db::transaction(function () use ($challenge): void {
            MemberAuthChallengeModel::query()->where('purpose', $challenge['purpose'])
                ->where('target_hash', $challenge['target_hash'])->where('binding_hash', $challenge['binding_hash'])
                ->whereIn('status', ['pending', 'active'])->update(['status' => 'superseded']);
            MemberAuthChallengeModel::query()->create($challenge);
        });
    }

    public function activate(string $id, bool $accepted): void
    {
        MemberAuthChallengeModel::query()->whereKey($id)->where('status', 'pending')
            ->update(['status' => $accepted ? 'active' : 'failed']);
    }

    public function consume(string $id, string $purpose, string $method, string $target,
        string $targetHash, string $bindingHash, string $codeHash, ?string $newPasswordHash = null): ?MemberAccount
    {
        return Db::transaction(function () use ($id, $purpose, $method, $target, $targetHash, $bindingHash, $codeHash, $newPasswordHash): ?MemberAccount {
            $challenge = MemberAuthChallengeModel::query()->whereKey($id)->lockForUpdate()->first();
            if ($challenge === null || $challenge->status !== 'active' || $challenge->purpose !== $purpose
                || $challenge->method !== $method || $challenge->expires_at <= date('Y-m-d H:i:s')
                || (int) $challenge->attempts >= 5 || !hash_equals($challenge->target_hash, $targetHash)
                || !hash_equals($challenge->binding_hash, $bindingHash)) {
                return null;
            }
            if (!hash_equals($challenge->code_hash, $codeHash)) {
                $challenge->attempts++;
                if ($challenge->attempts >= 5) $challenge->status = 'failed';
                $challenge->save();
                return null;
            }
            $member = MemberModel::query()->whereKey($challenge->member_id)->lockForUpdate()->first();
            if ($member === null || (int) $member->status !== 1
                || ($method === 'email' ? strtolower((string) $member->email) : $member->mobile) !== $target
                || !hash_equals(hash('sha256', $member->password_hash), $challenge->password_version)) {
                $challenge->status = 'failed';
                $challenge->save();
                return null;
            }
            if ($newPasswordHash !== null) {
                $member->password_hash = $newPasswordHash;
                MemberSessionModel::query()->where('member_id', $member->id)->delete();
            }
            $member->{$method . '_verified_at'} = date('Y-m-d H:i:s');
            $member->save();
            $challenge->status = 'used';
            $challenge->save();
            return new MemberAccount((int) $member->id, $member->mobile, $member->nickname,
                $member->password_hash, (int) $member->status, $member->public_id, $member->email);
        });
    }
}
