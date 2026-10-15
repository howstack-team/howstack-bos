<?php

declare(strict_types=1);

namespace app\modules\member\contract;

use app\modules\member\domain\MemberAccount;

interface MemberAuthChallengeRepositoryInterface
{
    /** @param array<string, mixed> $challenge 只含摘要的受控挑战数据 */
    public function issue(array $challenge): void;
    public function activate(string $id, bool $accepted): void;
    /** 成功才消费；密码重置与全部会话撤销在同一事务内提交。 */
    public function consume(string $id, string $purpose, string $method, string $target,
        string $targetHash, string $bindingHash, string $codeHash, ?string $newPasswordHash = null): ?MemberAccount;
}
