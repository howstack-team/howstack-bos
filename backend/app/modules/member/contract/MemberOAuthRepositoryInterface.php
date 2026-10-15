<?php

declare(strict_types=1);

namespace app\modules\member\contract;

use app\modules\member\domain\MemberAccount;
use app\shared\plugin\dto\oauth\OAuthIdentity;

interface MemberOAuthRepositoryInterface
{
    public function memberId(OAuthIdentity $identity): ?int;
    /** @param array{channel_id: int, revision: int, remember_me: bool} $attempt */
    public function issue(string $ticketHash, string $bindingHash, OAuthIdentity $identity, array $attempt): void;
    /** @return array{provider: string, app_id: string, subject: string, channel_id: int, channel_revision: int, remember_me: bool, expires_at: string}|null */
    public function pending(string $ticketHash, string $bindingHash): ?array;
    /** 原子校验账号当前密码版本、建立唯一关联并消费票据；错误次数提交后返回NULL。 */
    public function bind(string $ticketHash, string $bindingHash, ?MemberAccount $verified): ?MemberAccount;
}
