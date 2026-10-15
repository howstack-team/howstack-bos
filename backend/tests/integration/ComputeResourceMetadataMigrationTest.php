<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 自定义前缀历史升级及部分 DDL 已完成后的恢复。 */
final class ComputeResourceMetadataMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261008000900;
        $this->initializeDatabase();
    }

    public function testUpgradeAndPartialRetryPreserveExistingData(): void
    {
        $now = '2026-10-08 08:30:00';
        $base = ['code' => 'legacy', 'name' => '原有资源', 'description' => '保留原始说明', 'status' => 1, 'sort' => 5, 'created_at' => $now, 'updated_at' => $now];
        $region = $this->row('compute_regions', $base);
        $zone = $this->row('compute_zones', $base + ['region_id' => $region]);
        $line = $this->row('compute_lines', $base + ['zone_id' => $zone]);
        $original = (array) Db::table('compute_regions')->where('id', $region)->first();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        // 模拟第一个字段已提交、第二个尚未执行的升级中断。
        Db::connection()->getSchemaBuilder()->table('compute_regions', static function ($table): void {
            $table->string('country_code', 2)->default('')->comment('地域所属国家的两位大写字母代码；空字符串表示未配置');
        });
        Db::table('compute_regions')->where('id', $region)->update(['country_code' => 'CN']);
        self::assertSame([20261008001000], $runner->migrate($configuration)->executed);
        self::assertSame($original + ['country_code' => 'CN'], (array) Db::table('compute_regions')->where('id', $region)->first());
        self::assertSame('', Db::table('compute_lines')->where('id', $line)->value('test_ip'));
        Db::table('compute_lines')->where('id', $line)->update(['test_ip' => '2001:db8::1']);
        Db::table('migration_versions')->where('version', 20261008001000)->delete();
        self::assertSame([20261008001000], $runner->migrate($configuration)->executed);
        self::assertSame('2001:db8::1', Db::table('compute_lines')->where('id', $line)->value('test_ip'));
        self::assertSame($zone, (int) Db::table('compute_lines')->where('id', $line)->value('zone_id'));
        self::assertSame([], $runner->migrate($configuration)->executed);
        if (Db::connection()->getDriverName() === 'mysql') {
            $column = Db::selectOne('SELECT COLUMN_COMMENT, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$configuration->database, 'orm_compute_lines', 'test_ip']);
            self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->COLUMN_COMMENT);
            self::assertSame(45, (int) $column->CHARACTER_MAXIMUM_LENGTH);
        }
    }
}
