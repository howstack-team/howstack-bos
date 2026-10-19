<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\compute\infrastructure\model\{DiskTypeModel, FlavorModel};
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PdoSchemaInspector, PhinxCoreMigrationRunner, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 从新增价格字段前的真实迁移升级，验证默认价与自定义前缀不受影响。 */
final class ComputeZonePriceMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase {
        setUp as private createDatabase;
    }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261018000100;
        $this->createDatabase();
    }

    public function testUpgradePreservesDefaultPricesAndCanResumeWithCustomPrefix(): void
    {
        $now = date('Y-m-d H:i:s');
        $region = $this->row('compute_regions', ['code' => 'upgrade-region', 'name' => '升级地域', 'created_at' => $now, 'updated_at' => $now]);
        $zone = $this->row('compute_zones', ['code' => 'upgrade-zone', 'name' => '升级可用区', 'region_id' => $region, 'created_at' => $now, 'updated_at' => $now]);
        $group = $this->row('compute_flavor_groups', ['code' => 'upgrade-group', 'name' => '升级硬件组', 'created_at' => $now, 'updated_at' => $now]);
        $flavor = FlavorModel::query()->create([
            'code' => 'upgrade-flavor', 'name' => '升级规格', 'zone_id' => $zone, 'group_id' => $group,
            'cpu' => 2, 'memory_mib' => 2048, 'architecture' => 'x86_64', 'prices' => ['month' => 12345],
        ]);
        $disk = DiskTypeModel::query()->create([
            'code' => 'upgrade-disk', 'name' => '升级存储', 'zone_id' => $zone,
            'min_gib' => 20, 'max_gib' => 200, 'step_gib' => 1, 'max_count' => 2, 'prices' => ['month' => 199],
        ]);
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        foreach ([1, 2] as $attempt) {
            $runner->migrate($configuration);
            self::assertSame(['month' => 12345], $flavor->fresh()->prices);
            self::assertSame(['month' => 199], $disk->fresh()->prices);
            self::assertNull($flavor->fresh()->zone_prices);
            self::assertNull($disk->fresh()->zone_prices);
        }
        if (Db::connection()->getDriverName() === 'mysql') {
            $rows = Db::select("SELECT COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?) AND COLUMN_NAME = 'zone_prices'", [
                $configuration->tablePrefix . 'compute_flavors', $configuration->tablePrefix . 'compute_disk_types',
            ]);
            self::assertCount(2, $rows);
            foreach ($rows as $row) {
                self::assertStringContainsString('整数分', $row->comment);
                self::assertStringContainsString('0表示免费', $row->comment);
            }
        }
    }
}
