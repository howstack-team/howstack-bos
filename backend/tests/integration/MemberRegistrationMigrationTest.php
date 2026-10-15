<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\member\infrastructure\DatabaseMemberRepository;
use app\modules\order\infrastructure\DatabaseOrderMemberReference;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class MemberRegistrationMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }
    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261008000400;
        $this->initializeDatabase();
    }
    public function testUpgradeBackfillsUniquePublicIdsWithoutChangingPrimaryKeysOrdersOrSorting(): void
    {
        $first = $this->member('13800138001'); $second = $this->member('13800138002');
        $order = $this->order($first);
        Db::table('members')->where('id', $first)->update(['created_at' => '2026-10-08 12:00:00']);
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261008000500, 20261008000600, 20261008000700, 20261008000800, 20261008000900, 20261008001000, 20261009000100], $runner->migrate($configuration)->executed);
        self::assertSame('注册与登录', Db::table('system_setting_categories')->where('code', 'registration')->value('name'));
        $numbers = Db::table('members')->orderBy('id')->pluck('public_id', 'id')->all();
        self::assertSame([$first, $second], array_keys($numbers));
        self::assertCount(2, array_unique($numbers));
        foreach ($numbers as $number) self::assertMatchesRegularExpression('/^[1-9][0-9]{7}$/D', $number);
        self::assertSame($first, (int) Db::table('orders')->where('id', $order)->value('member_id'));
        $repo = new DatabaseMemberRepository(new DatabaseOrderMemberReference());
        self::assertSame([$first, $second], array_column($repo->paginate('', null, 1, 10)['data'], 'id'));
        self::assertSame([$second, $first], array_column($repo->paginate('', null, 1, 10, ['sort_by' => 'created_at', 'sort_order' => 'asc'])['data'], 'id'));
        self::assertSame([], $runner->migrate($configuration)->executed);
        self::assertSame($numbers, Db::table('members')->orderBy('id')->pluck('public_id', 'id')->all());
        if (Db::connection()->getDriverName() === 'mysql') {
            $comments = Db::select("SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orm_member_registration_challenges'");
            foreach ($comments as $column) self::assertNotSame('', $column->COLUMN_COMMENT);
            self::assertSame('varchar(9)', Db::select("SHOW COLUMNS FROM orm_members LIKE 'public_id'")[0]->Type);
        }
    }
}
