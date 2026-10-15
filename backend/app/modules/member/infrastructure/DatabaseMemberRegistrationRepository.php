<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\exception\BusinessException;
use app\modules\member\contract\MemberRegistrationRepositoryInterface;
use app\modules\member\domain\MemberAccount;
use app\modules\member\infrastructure\model\{MemberModel, RegistrationChallengeModel};
use app\shared\http\ApiErrorCode;
use Illuminate\Database\QueryException;
use support\Db;

final class DatabaseMemberRegistrationRepository implements MemberRegistrationRepositoryInterface
{
    public function exists(string $method, string $target): bool
    {
        return $method === 'email'
            ? MemberModel::query()->whereRaw('LOWER(email) = ?', [strtolower($target)])->exists()
            : MemberModel::query()->where('mobile', $target)->exists();
    }

    public function create(string $method, string $target, string $passwordHash, bool $verified): MemberAccount
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $row = Db::transaction(static fn (): MemberModel => MemberModel::query()->create([
                    'public_id' => \app\modules\member\domain\PublicMemberId::generate(), 'nickname' => '会员', 'mobile' => $method === 'mobile' ? $target : null,
                    'email' => $method === 'email' ? $target : null, 'password_hash' => $passwordHash,
                    'status' => 1, $method . '_verified_at' => $verified ? date('Y-m-d H:i:s') : null,
                ]));
                return new MemberAccount($row->id, $row->mobile, $row->nickname, $row->password_hash, $row->status, $row->public_id, $row->email);
            } catch (QueryException $error) {
                if (!in_array($error->getCode(), ['23000', '23505'], true)) throw $error;
                if ($this->exists($method, $target)) throw new BusinessException('该账号已注册，请直接登录', ApiErrorCode::CONFLICT, 409);
                // 公开编号随机碰撞只重试编号；不修改现有会员或联系人。
            }
        }
        throw new BusinessException('会员编号生成失败，请稍后重试', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
    }

    public function issueChallenge(array $values): void
    {
        Db::transaction(static function () use ($values): void {
            RegistrationChallengeModel::query()->where('target_hash', $values['target_hash'])->where('binding_hash', $values['binding_hash'])
                ->whereIn('status', ['active', 'pending'])->update(['status' => 'superseded']);
            RegistrationChallengeModel::query()->create($values);
        });
    }

    public function activateChallenge(string $id, bool $accepted): void
    {
        RegistrationChallengeModel::query()->where('challenge_id', $id)->where('status', 'pending')->update(['status' => $accepted ? 'active' : 'failed']);
    }

    public function consumeChallenge(string $id, string $method, string $targetHash, string $bindingHash, string $codeHash): bool
    {
        return Db::transaction(static function () use ($id, $method, $targetHash, $bindingHash, $codeHash): bool {
            $row = RegistrationChallengeModel::query()->where('challenge_id', $id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'active' || $row->method !== $method || $row->expires_at <= date('Y-m-d H:i:s') || $row->attempts >= 5
                || !hash_equals($row->target_hash, $targetHash) || !hash_equals($row->binding_hash, $bindingHash)) return false;
            if (!hash_equals($row->code_hash, $codeHash)) {
                $row->attempts++;
                if ($row->attempts >= 5) $row->status = 'failed';
                $row->save();
                return false;
            }
            $row->status = 'used'; $row->save();
            return true;
        });
    }
}
