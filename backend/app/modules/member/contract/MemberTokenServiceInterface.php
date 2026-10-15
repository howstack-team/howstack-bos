<?php

declare(strict_types=1);

namespace app\modules\member\contract;

use app\modules\member\domain\MemberAccount;

interface MemberTokenServiceInterface
{
    public function issue(MemberAccount $account, bool $remember = false): string;

    public function revoke(string $token): void;

    public function parse(string $token): string;

    public function matchesPasswordVersion(string $token, MemberAccount $account): bool;
}
