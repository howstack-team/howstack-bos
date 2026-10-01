<?php

declare(strict_types=1);

namespace tests\unit\modules\member\infrastructure;

use app\exception\BusinessException;
use app\modules\member\domain\MemberAccount;
use app\modules\member\infrastructure\FirebaseMemberTokenService;
use PHPUnit\Framework\TestCase;

final class FirebaseMemberTokenServiceTest extends TestCase
{
    public function testMemberTokenCannotBeParsedByAnotherAudienceAndPasswordChangeRevokesIt(): void
    {
        $secret = str_repeat('s', 64);
        $member = new MemberAccount(7, '13800138007', '会员', password_hash('password', PASSWORD_DEFAULT), 1);
        $tokens = new FirebaseMemberTokenService($secret, 'hlyq-test', 'hlyq-member', 3600);
        $token = $tokens->issue($member);

        self::assertSame(7, $tokens->parse($token));
        self::assertTrue($tokens->matchesPasswordVersion($token, $member));
        self::assertFalse($tokens->matchesPasswordVersion($token,
            new MemberAccount(7, '13800138007', '会员', password_hash('new-password', PASSWORD_DEFAULT), 1)));

        $adminAudience = new FirebaseMemberTokenService($secret, 'hlyq-test', 'hlyq-admin', 3600);
        $this->expectException(BusinessException::class);
        $adminAudience->parse($token);
    }
}
