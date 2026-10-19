<?php

declare(strict_types=1);
namespace tests\integration;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
final class AdminMfaMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initialize; }
    protected function setUp(): void { $this->initialMigrationVersion = 20261015000300; $this->initialize(); }
    public function testExistingAccountDataSurvivesAndUpgradeIsRepeatable(): void
    {
        $before = (array) Db::table('admin_users')->where('id', 1)->first();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $cfg = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertContains(20261015000400, $runner->migrate($cfg)->executed);
        $after = (array) Db::table('admin_users')->where('id', 1)->first();
        self::assertSame(0, (int) $after['auth_version']); unset($after['auth_version']);
        self::assertSame($before, $after);
        self::assertSame(0, Db::table('admin_mfa_credentials')->count());
        self::assertSame([], $runner->migrate($cfg)->executed);
    }
}
