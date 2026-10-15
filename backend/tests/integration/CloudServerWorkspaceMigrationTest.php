<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\exception\SystemUpdateException;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 使用旧结构和自定义前缀验证迁移；支持独立 MySQL 测试库。 */
final class CloudServerWorkspaceMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261002000300;
        $this->initializeDatabase();
    }

    public function testLegacyOffersRebindWithoutChangingDescriptionsVersionsOrMoney(): void
    {
        [$first, $second, $version] = $this->history();
        $member = $this->member();
        $this->order($member);
        $runner = $this->runner();
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261003000100, 20261003000200, 20261003000300, 20261004000100, 20261004000200, 20261006000100, 20261006000200, 20261006000300, 20261006000400, 20261007000100, 20261007000200, 20261007000300], $runner->migrate($configuration)->executed);
        $fixed = (int) Db::table('products')->where('definition_key', 'cloud_server')->value('id');
        self::assertSame(3, Db::table('products')->count());
        self::assertSame(2, Db::table('offers')->where('product_id', $fixed)->count());
        self::assertSame('香港描述', Db::table('offers')->where('id', $first)->value('description'));
        self::assertSame('活动描述', Db::table('offers')->where('id', $second)->value('description'));
        self::assertSame($version, (int) Db::table('offers')->where('id', $first)->value('published_version_id'));
        self::assertSame('{"product_name":"历史香港云"}', Db::table('offer_versions')->value('snapshot'));
        self::assertSame(str_repeat('a', 64), Db::table('offer_versions')->value('snapshot_hash'));
        self::assertSame(1200, (int) Db::table('orders')->sum('payable_minor'));
        self::assertFalse(Db::getSchemaBuilder()->hasColumn('products', 'product_type'));
        self::assertSame([], $runner->migrate($configuration)->executed);
        Db::table('offers')->where('id', $first)->update(['description' => '迁移后编辑']);
        Db::table('migration_versions')->where('version', 20261003000100)->delete();
        self::assertSame([20261003000100], $runner->migrate($configuration)->executed);
        self::assertSame('迁移后编辑', Db::table('offers')->where('id', $first)->value('description'));
        self::assertSame(3, Db::table('products')->count());
        self::assertSame(1, Db::table('offer_versions')->count());
    }

    public function testInterruptedSchemaExpansionCanResume(): void
    {
        [$first] = $this->history();
        Db::statement('ALTER TABLE orm_products ADD definition_key VARCHAR(64) NULL');
        Db::statement('ALTER TABLE orm_offers ADD description VARCHAR(1000) NULL');
        Db::table('offers')->where('id', $first)->update(['description' => '已完成部分回填']);
        $this->runner()->migrate(MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig()));
        self::assertSame(1, Db::table('products')->where('definition_key', 'cloud_server')->count());
        self::assertSame('已完成部分回填', Db::table('offers')->where('id', $first)->value('description'));
        self::assertSame('活动描述', Db::table('offers')->where('code', 'promotion')->value('description'));
        self::assertTrue(Db::getSchemaBuilder()->hasTable('compute_network_policies'));
        self::assertTrue(Db::getSchemaBuilder()->hasTable('cloud_delivery_rules'));
    }

    public function testWrongPublishedVersionOwnerFailsBeforeSchemaChange(): void
    {
        [, $second, $version] = $this->history();
        Db::table('offers')->where('id', $second)->update(['published_version_id' => $version]);
        try {
            $this->runner()->migrate(MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig()));
            self::fail('错误版本归属必须阻止迁移');
        } catch (SystemUpdateException $error) {
            self::assertStringContainsString('发布版本归属无效', $error->getPrevious()->getMessage());
        }
        self::assertFalse(Db::getSchemaBuilder()->hasColumn('products', 'definition_key'));
        self::assertTrue(Db::getSchemaBuilder()->hasColumn('products', 'product_type'));
        self::assertSame(2, Db::table('products')->count());
    }

    /** @return array{int, int, int} 两份旧方案及发布版本。 */
    private function history(): array
    {
        $now = '2026-10-02 08:30:00';
        $offers = [];
        foreach ([['历史香港云', 'hong-kong', '香港描述'], ['历史活动云', 'promotion', '活动描述']] as [$name, $code, $description]) {
            $product = $this->row('products', ['name' => $name, 'product_type' => 'cloud_server', 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
            $offers[] = $this->row('offers', ['product_id' => $product, 'name' => $name, 'code' => $code, 'draft' => '{}', 'revision' => 2, 'created_at' => $now, 'updated_at' => $now]);
        }
        $version = $this->row('offer_versions', ['offer_id' => $offers[0], 'version' => 1, 'type_version' => '1.0.0',
            'snapshot' => '{"product_name":"历史香港云"}', 'snapshot_hash' => str_repeat('a', 64), 'published_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        Db::table('offers')->where('id', $offers[0])->update(['published_version_id' => $version]);
        return [$offers[0], $offers[1], $version];
    }

    private function runner(): PhinxCoreMigrationRunner
    {
        return new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
    }
}
