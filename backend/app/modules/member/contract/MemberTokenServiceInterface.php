<?php

declare(strict_types=1);

namespace app\modules\member\contract;

use app\modules\member\domain\MemberAccount;

interface MemberTokenServiceInterface
{
    public function issue(MemberAccount $account): string;

    public function parse(string $token): int;

    public function matchesPasswordVersion(string $token, MemberAccount $account): bool;
}
