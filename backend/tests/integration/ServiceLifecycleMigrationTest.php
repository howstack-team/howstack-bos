<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\service_instance\infrastructure\model\ServiceInstanceModel;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PdoSchemaInspector, PhinxCoreMigrationRunner, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 从前一实际迁移升级，保留既有实例，并验证自定义前缀和中文注释。 */
final class ServiceLifecycleMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase {
        setUp as private createDatabase;
    }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261018000200;
        $this->createDatabase();
    }

    public function testUpgradeIsRepeatablePreservesInstancesAndHasChineseComments(): void
    {
        $member = $this->member();
        $service = ServiceInstanceModel::query()->create(['instance_no' => 'migration-lifecycle-test', 'product_type' => 'cloud_server',
            'member_id' => $member, 'display_name' => '原实例', 'status' => 'active', 'expires_at' => '2027-01-31 12:00:00']);
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $runner->migrate($configuration);
            self::assertSame('2027-01-31 12:00:00', $service->fresh()->getRawOriginal('expires_at'));
            self::assertSame('active', $service->fresh()->status);
            self::assertSame(0, Db::table('service_lifecycle_tasks')->count());
        }
        if (Db::connection()->getDriverName() === 'mysql') {
            $columns = Db::select('SELECT COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$configuration->tablePrefix . 'service_lifecycle_tasks']);
            self::assertCount(22, $columns);
            foreach ($columns as $column) {
                self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->comment);
            }
        }
    }
}
