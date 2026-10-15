<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\exception\BusinessException;
use app\modules\member\contract\MemberOAuthRepositoryInterface;
use app\modules\member\domain\MemberAccount;
use app\modules\member\infrastructure\model\{MemberOAuthIdentityModel, MemberOAuthPendingModel, MemberModel};
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\oauth\OAuthIdentity;
use Illuminate\Database\QueryException;
use support\Db;

final class DatabaseMemberOAuthRepository implements MemberOAuthRepositoryInterface
{
    public function memberId(OAuthIdentity $identity): ?int
    {
        $id = MemberOAuthIdentityModel::query()->where('provider', $identity->provider)
            ->where('app_id', $identity->appId)->where('subject', $identity->subject)->value('member_id');
        return $id === null ? null : (int) $id;
    }

    public function issue(string $ticketHash, string $bindingHash, OAuthIdentity $identity, array $attempt): void
    {
        Db::transaction(function () use ($ticketHash, $bindingHash, $identity, $attempt): void {
            MemberOAuthPendingModel::query()->where('expires_at', '<=', date('Y-m-d H:i:s'))->delete();
            // 同一浏览器新授权替换旧待关联任务，避免关联到旧授权账号。
            MemberOAuthPendingModel::query()->where('binding_hash', $bindingHash)->delete();
            MemberOAuthPendingModel::query()->create(['ticket_hash' => $ticketHash, 'binding_hash' => $bindingHash,
                'provider' => $identity->provider, 'app_id' => $identity->appId, 'subject' => $identity->subject,
                'channel_id' => $attempt['channel_id'], 'channel_revision' => $attempt['revision'],
                'remember_me' => $attempt['remember_me'], 'attempts' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 600), 'created_at' => date('Y-m-d H:i:s')]);
        });
    }

    public function pending(string $ticketHash, string $bindingHash): ?array
    {
        $row = MemberOAuthPendingModel::query()->whereKey($ticketHash)->where('binding_hash', $bindingHash)
            ->where('attempts', '<', 5)->where('expires_at', '>', date('Y-m-d H:i:s'))->first();
        return $row === null ? null : $row->only(['provider', 'app_id', 'subject', 'channel_id', 'channel_revision', 'remember_me', 'expires_at']);
    }

    public function bind(string $ticketHash, string $bindingHash, ?MemberAccount $verified): ?MemberAccount
    {
        try {
            return Db::transaction(function () use ($ticketHash, $bindingHash, $verified): ?MemberAccount {
                $row = MemberOAuthPendingModel::query()->whereKey($ticketHash)->lockForUpdate()->first();
                if ($row === null || !hash_equals($row->binding_hash, $bindingHash) || $row->attempts >= 5 || $row->expires_at <= date('Y-m-d H:i:s')) return null;
                $member = $verified === null ? null : MemberModel::query()->whereKey($verified->id)->lockForUpdate()->first();
                if ($member === null || (int) $member->status !== 1 || !hash_equals($verified->passwordHash, $member->password_hash)) {
                    $row->attempts++;
                    $row->save();
                    return null;
                }
                $identity = ['provider' => $row->provider, 'app_id' => $row->app_id, 'subject' => $row->subject];
                $existing = MemberOAuthIdentityModel::query()->where($identity)->lockForUpdate()->first();
                if ($existing !== null && (int) $existing->member_id !== $member->id) throw $this->conflict();
                if ($existing === null) MemberOAuthIdentityModel::query()->create($identity + ['member_id' => $member->id, 'created_at' => date('Y-m-d H:i:s')]);
                $row->delete();
                return new MemberAccount((int) $member->id, $member->mobile, $member->nickname,
                    $member->password_hash, (int) $member->status, $member->public_id, $member->email);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) throw $this->conflict($exception);
            throw $exception;
        }
    }

    private function conflict(?\Throwable $previous = null): BusinessException
    {
        return new BusinessException('第三方账号或平台账号已经存在关联，请使用原账号登录', ApiErrorCode::CONFLICT, 409, previous: $previous);
    }
}
