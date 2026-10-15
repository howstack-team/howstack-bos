<?php

declare(strict_types=1);
namespace app\modules\admin\auth\domain;
/** 密码通过后的临时挑战，不能用于任何受保护接口。 */
final readonly class AdminMfaLoginChallenge
{
    public function __construct(public string $token, public int $expiresAt) {}
}
