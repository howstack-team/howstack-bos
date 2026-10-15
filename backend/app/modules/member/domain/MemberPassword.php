<?php

declare(strict_types=1);

namespace app\modules\member\domain;

/** 不存在的账号也执行密码验证；统一限制bcrypt的72字节边界。 */
final class MemberPassword
{
    private const string DUMMY_HASH = '$2y$12$yQV9LRqZiZ713M/aLmGQtulk/tMe2T2A5496vpdz/AUUeijRMjlX6';
    public static function matches(?MemberAccount $account, string $password): bool
    {
        $matches = password_verify($password, $account?->passwordHash ?? self::DUMMY_HASH);
        return strlen($password) <= 72 && $account !== null && $account->status === 1 && $matches;
    }
}
