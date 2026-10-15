<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 旧单关联回填、多可用区唯一约束及扩展字段可重复升级。 */
final class CloudResourceExpansionMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261003000100;
        $this->initializeDatabase();
    }

    public function testOldRelationsAndSnapshotsSurviveUpgradeAndRetry(): void
    {
        $this->seedResources();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261003000200, 20261003000300, 20261004000100, 20261004000200, 20261006000100, 20261006000200, 20261006000300, 20261006000400, 20261007000100, 20261007000200, 20261007000300], $runner->migrate($configuration)->executed);
        foreach (['compute_line_zones', 'compute_flavor_zones', 'compute_image_zones', 'compute_disk_type_zones', 'compute_protection_lines'] as $table) self::assertSame(1, Db::table($table)->count());
        self::assertSame(1, (int) Db::table('compute_images')->value('min_cpu'));
        self::assertSame(128, (int) Db::table('compute_images')->value('min_memory_mib'));
        self::assertNull(Db::table('compute_disk_types')->value('read_mbps'));
        self::assertNull(Db::table('compute_flavor_groups')->value('base_clock_mhz'));
        $snapshot = '{"resources":{"images":[{"id":1,"zone_id":1}]}}';
        $offer = $this->row('offers', ['product_id' => Db::table('products')->value('id'), 'name' => '旧销售', 'code' => 'old', 'draft' => '{}', 'created_at' => '2026-10-03 00:00:00', 'updated_at' => '2026-10-03 00:00:00']);
        $this->row('offer_versions', ['offer_id' => $offer, 'version' => 1, 'type_version' => '1.1.0', 'snapshot' => $snapshot, 'snapshot_hash' => str_repeat('b', 64), 'published_by' => 1, 'created_at' => '2026-10-03 00:00:00', 'updated_at' => '2026-10-03 00:00:00']);
        Db::table('compute_image_zones')->insert(['image_id' => 1, 'zone_id' => 2]);
        Db::table('compute_flavor_groups')->update(['cpu_model' => 'AMD EPYC', 'base_clock_mhz' => 3100]);
        Db::table('migration_versions')->where('version', 20261003000200)->delete();
        self::assertSame([20261003000200], $runner->migrate($configuration)->executed);
        self::assertSame(2, Db::table('compute_image_zones')->count());
        self::assertSame('AMD EPYC', Db::table('compute_flavor_groups')->value('cpu_model'));
        self::assertSame($snapshot, Db::table('offer_versions')->value('snapshot'));
        self::assertSame(str_repeat('b', 64), Db::table('offer_versions')->value('snapshot_hash'));
        self::assertSame([], $runner->migrate($configuration)->executed);
    }

    public function testSupplierMappingCanDifferPerZoneButCannotDuplicateSameScope(): void
    {
        $this->seedResources();
        (new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector()))->migrate(MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig()));
        $now = '2026-10-03 00:00:00';
        $connection = $this->row('provider_connections', ['name' => '供应商', 'adapter_id' => 'magic_cloud', 'credentials_encrypted' => 'test-only', 'capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $mapping = ['connection_id' => $connection, 'resource_kind' => 'images', 'local_id' => 1, 'external_id' => 'image-a', 'created_at' => $now, 'updated_at' => $now];
        $this->row('provider_resource_mappings', $mapping + ['scope_zone_id' => 0]);
        $this->row('provider_resource_mappings', array_replace($mapping, ['scope_zone_id' => 2, 'external_id' => 'image-b']));
        self::assertSame(2, Db::table('provider_resource_mappings')->count());
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->row('provider_resource_mappings', $mapping + ['scope_zone_id' => 2]);
    }

    /** @return void 旧结构的测试资源。 */
    private function seedResources(): void
    {
        $common = ['code' => 'fixture', 'name' => '测试资源', 'created_at' => '2026-10-03 00:00:00', 'updated_at' => '2026-10-03 00:00:00'];
        $this->row('compute_regions', $common);
        $this->row('compute_zones', $common + ['region_id' => 1]);
        $this->row('compute_zones', array_replace($common, ['code' => 'zone-2', 'region_id' => 1]));
        $this->row('compute_lines', $common + ['zone_id' => 1]);
        $this->row('compute_flavor_groups', $common);
        $this->row('compute_flavors', $common + ['zone_id' => 1, 'group_id' => 1, 'cpu' => 2, 'memory_mib' => 2048, 'architecture' => 'x86_64', 'prices' => '{"month":100}']);
        $this->row('compute_image_types', $common);
        $this->row('compute_images', $common + ['zone_id' => 1, 'type_id' => 1, 'architecture' => 'x86_64', 'min_disk_gib' => 20, 'login_methods' => '["password"]']);
        $this->row('compute_disk_types', $common + ['zone_id' => 1, 'min_gib' => 20, 'max_gib' => 200, 'step_gib' => 1, 'max_count' => 8, 'prices' => '{"month":10}']);
        $this->row('compute_protections', $common + ['line_id' => 1, 'prices' => '{"month":100}']);
    }
}
