<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\member\domain\PublicMemberId;
use app\modules\member\infrastructure\DatabaseMemberAuthRepository;
use app\modules\member\infrastructure\FirebaseMemberTokenService;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 验证已有八位编号升级后的身份、令牌及唯一索引保持有效。 */
final class MemberPublicIdExpansionTest extends TestCase
{
    use IsolatedBusinessDatabase {
        setUp as private initializeDatabase;
    }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261008001000;
        $this->initializeDatabase();
    }

    public function testUpgradePreservesLegacyIdentityAndSupportsNineDigitMembers(): void
    {
        $now = '2026-10-09 08:00:00';
        $legacyId = (int) Db::table('members')->insertGetId([
            'public_id' => '72846193',
            'mobile' => '13800138001',
            'nickname' => '历史会员',
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = $this->order($legacyId);
        $members = new DatabaseMemberAuthRepository();
        $legacy = $members->findByPublicId('72846193');
        $sessions = $this->createStub(\app\modules\member\contract\MemberSessionRepositoryInterface::class);
        $sessions->method('active')->willReturn(true);
        $tokens = new FirebaseMemberTokenService(str_repeat('s', 64), 'test', 'member', 3600, $sessions);
        $legacyToken = $tokens->issue($legacy);
        $before = Db::table('members')->where('id', $legacyId)->first();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());

        self::assertContains(20261009000100, $runner->migrate($configuration)->executed);
        self::assertEquals($before, Db::table('members')->where('id', $legacyId)->first());
        self::assertSame($legacyId, (int) Db::table('orders')->where('id', $orderId)->value('member_id'));
        self::assertSame('72846193', $tokens->parse($legacyToken));
        self::assertSame('72846193', $members->findByPublicId('72846193')->profile()['id']);

        $newId = $this->member('13800138002');
        $number = Db::table('members')->where('id', $newId)->value('public_id');
        self::assertMatchesRegularExpression('/^[1-9][0-9]{8}$/D', $number);
        $newMember = $members->findByPublicId($number);
        self::assertSame($newId, $newMember->id);
        self::assertSame($number, $tokens->parse($tokens->issue($newMember)));
        self::assertSame([], $runner->migrate($configuration)->executed);
        self::assertSame($number, Db::table('members')->where('id', $newId)->value('public_id'));

        if (Db::connection()->getDriverName() === 'mysql') {
            $column = Db::select("SHOW FULL COLUMNS FROM orm_members LIKE 'public_id'")[0];
            self::assertSame('varchar(9)', $column->Type);
            self::assertSame('NO', $column->Null);
            self::assertStringContainsString('九位', $column->Comment);
        }

        $this->expectException(QueryException::class);
        Db::table('members')->where('id', $newId)->update(['public_id' => '72846193']);
    }

    public function testValidationAcceptsCurrentAndLegacyIdsWithoutRelaxingOtherFormats(): void
    {
        foreach (['100000000', '999999999', '10000000', '99999999'] as $value) {
            self::assertTrue(PublicMemberId::valid($value));
        }
        foreach (['1234567', '1000000000', '012345678', '12345678a', '123456789\n', ' 123456789'] as $value) {
            self::assertFalse(PublicMemberId::valid($value));
        }
    }
}
