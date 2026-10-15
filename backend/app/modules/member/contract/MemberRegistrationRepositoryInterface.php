<?php

declare(strict_types=1);

namespace app\modules\member\contract;

use app\modules\member\domain\MemberAccount;

interface MemberRegistrationRepositoryInterface
{
    public function exists(string $method, string $target): bool;
    public function create(string $method, string $target, string $passwordHash, bool $verified): MemberAccount;
    public function issueChallenge(array $values): void;
    public function activateChallenge(string $id, bool $accepted): void;
    /** 错误尝试计数及成功消费分别原子提交，失败校验不回滚计数。 */
    public function consumeChallenge(string $id, string $method, string $targetHash, string $bindingHash, string $codeHash): bool;
}
