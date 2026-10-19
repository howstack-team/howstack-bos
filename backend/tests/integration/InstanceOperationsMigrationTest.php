<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\service_instance\infrastructure\model\ServiceInstanceModel;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PdoSchemaInspector, PhinxCoreMigrationRunner, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 原实例和已完成生命周期任务必须在升级后保留，兼容自定义表前缀。 */
final class InstanceOperationsMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private createDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261018000300;
        $this->createDatabase();
    }

    public function testForwardUpgradePreservesExistingRecordsAndHasChineseComments(): void
    {
        $service = ServiceInstanceModel::query()->create(['instance_no' => 'operations-migration', 'product_type' => 'cloud_server',
            'member_id' => $this->member(), 'display_name' => '原实例', 'status' => 'active', 'expires_at' => '2027-01-31 12:00:00']);
        $now = date('Y-m-d H:i:s');
        Db::table('service_lifecycle_tasks')->insert(['service_id' => $service->id, 'action' => 'notice7', 'request_key' => 'original-notice',
            'expected_expires_at' => '2027-01-31 12:00:00', 'target_expires_at' => '2027-01-31 12:00:00', 'status' => 'succeeded',
            'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $runner->migrate($configuration);
            self::assertSame('active', $service->fresh()->status);
            self::assertSame('2027-01-31 12:00:00', $service->fresh()->getRawOriginal('expires_at'));
            self::assertNull($service->fresh()->power_status);
            self::assertSame('original-notice', Db::table('service_lifecycle_tasks')->value('request_key'));
            self::assertSame(1, Db::table('service_lifecycle_tasks')->count());
        }
        if (Db::connection()->getDriverName() === 'mysql') {
            foreach (['service_lifecycle_tasks' => ['operation_context', 'password_encrypted', 'password_digest', 'credential_expires_at', 'provider_task_id'],
                'service_instances' => ['power_status', 'power_checked_at', 'installed_image', 'initial_password_disabled_at']] as $table => $fields) {
                $columns = Db::select('SELECT COLUMN_NAME AS name, COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$configuration->tablePrefix . $table]);
                foreach ($fields as $field) {
                    $column = array_values(array_filter($columns, static fn (object $row): bool => $row->name === $field));
                    self::assertCount(1, $column);
                    self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column[0]->comment);
                }
            }
        }
    }
}
