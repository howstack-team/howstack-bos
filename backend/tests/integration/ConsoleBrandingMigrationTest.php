<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class ConsoleBrandingMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261011000900;
        $this->initializeDatabase();
    }

    public function testUpdatesBuiltinEntryAndPreservesOtherChoicesAndRollback(): void
    {
        $this->upgrade('official-console', true);
    }

    public function testDoesNotReplaceThirdPartyEntry(): void
    {
        $this->upgrade('third-party', false);
    }

    private function upgrade(string $themeId, bool $shouldUpgrade): void
    {
        $now = date('Y-m-d H:i:s');
        $old = Db::table('site_theme_releases')->insertGetId([
            'theme_id' => $themeId, 'name' => '原入口', 'version' => '0.0.2', 'digest' => bin2hex(random_bytes(32)),
            'components' => '["responsive"]', 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Db::table('site_theme_configurations')->insert([
            'release_id' => $old, 'component' => 'responsive', 'settings' => '{}', 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Db::table('console_theme_bindings')->where('id', 1)->update(['responsive' => $old, 'desktop' => $old, 'revision' => 17]);
        $website = Db::table('website_settings')->where('id', 1)->first();
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        [$manager] = (new \ReflectionMethod($runner, 'manager'))->invoke($runner, $configuration);
        $manager->migrate('runtime', 20261011001000);
        $binding = Db::table('console_theme_bindings')->where('id', 1)->first();
        self::assertSame($old, (int) $binding->desktop);
        self::assertSame($shouldUpgrade ? 18 : 17, (int) $binding->revision);
        if ($shouldUpgrade) {
            self::assertNotSame($old, (int) $binding->responsive);
            self::assertSame('official-console', Db::table('site_theme_releases')->where('id', $binding->responsive)->value('theme_id'));
        } else {
            self::assertSame($old, (int) $binding->responsive);
        }
        self::assertTrue(Db::table('site_theme_releases')->where('id', $old)->exists());
        self::assertTrue(Db::table('site_theme_configurations')->where('release_id', $old)->exists());
        self::assertEquals($website, Db::table('website_settings')->where('id', 1)->first());
        $manager->migrate('runtime', 20261011001000);
    }
}
