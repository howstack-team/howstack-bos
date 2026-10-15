<?php

declare(strict_types=1);

namespace tests\unit\modules\member\infrastructure;

use app\exception\BusinessException;
use app\modules\member\domain\MemberAccount;
use app\modules\member\infrastructure\FirebaseMemberTokenService;
use PHPUnit\Framework\TestCase;

final class FirebaseMemberTokenServiceTest extends TestCase
{
    public function testNewSubjectDoesNotExposeInternalIdAndOldNumericSubjectIsRejected(): void
    {
        $secret = str_repeat('s', 64);
        $tokens = new FirebaseMemberTokenService($secret, 'test', 'member', 3600, $this->sessionStore());
        $account = new MemberAccount(7, null, '会员', 'hash', 1, '72846193', 'a@example.com');
        $payload = (array) \Firebase\JWT\JWT::decode($tokens->issue($account), new \Firebase\JWT\Key($secret, 'HS256'));
        self::assertSame('72846193', $payload['sub']);
        self::assertSame(2, $payload['sv']);
        unset($payload['sv']); $payload['sub'] = '7';
        $this->expectException(BusinessException::class);
        $tokens->parse(\Firebase\JWT\JWT::encode($payload, $secret, 'HS256'));
    }

    public function testMemberTokenCannotBeParsedByAnotherAudienceAndPasswordChangeRevokesIt(): void
    {
        $secret = str_repeat('s', 64);
        $member = new MemberAccount(7, '13800138007', '会员', password_hash('password', PASSWORD_DEFAULT), 1, '72846193');
        $tokens = new FirebaseMemberTokenService($secret, 'hlyq-test', 'hlyq-member', 3600, $this->sessionStore());
        $token = $tokens->issue($member);

        self::assertSame('72846193', $tokens->parse($token));
        self::assertTrue($tokens->matchesPasswordVersion($token, $member));
        self::assertFalse($tokens->matchesPasswordVersion($token,
            new MemberAccount(7, '13800138007', '会员', password_hash('new-password', PASSWORD_DEFAULT), 1, '72846193')));

        $adminAudience = new FirebaseMemberTokenService($secret, 'hlyq-test', 'hlyq-admin', 3600, $this->sessionStore());
        $this->expectException(BusinessException::class);
        $adminAudience->parse($token);
    }
    private function sessionStore(): \app\modules\member\contract\MemberSessionRepositoryInterface
    {
        $store = $this->createStub(\app\modules\member\contract\MemberSessionRepositoryInterface::class);
        $store->method('active')->willReturn(true);
        return $store;
    }
}
