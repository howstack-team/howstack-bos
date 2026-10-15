<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class DatabaseFileCategoryMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261011000500;
        $this->initializeDatabase();
    }

    public function testUpgradeReclassifiesDatabaseFilesAndPreservesOtherMetadata(): void
    {
        foreach (['SQL', 'db', 'sqlite', 'sqlite3', 'txt', 'zip'] as $index => $extension) {
            Db::table('file_assets')->insert([
                'id' => str_pad((string) $index, 32, '0'), 'original_name' => '文件.' . $extension,
                'extension' => $extension, 'type' => $index % 2 ? 'other' : 'document',
                'namespace' => 'files', 'visibility' => $index % 2 ? 'private' : 'public',
                'origin' => 'library', 'mime_type' => 'text/plain', 'byte_size' => 4,
                'channel_id' => 1, 'channel_revision' => 1, 'object_key' => 'files/' . $extension,
                'created_by' => 1, 'status' => 'stored', 'created_at' => '2026-10-11 12:00:00',
            ]);
        }
        $before = Db::table('file_assets')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        [$manager] = (new \ReflectionMethod($runner, 'manager'))->invoke($runner, $configuration);
        $manager->migrate('runtime', 20261011000600);
        $after = Db::table('file_assets')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        foreach ($after as $index => $row) {
            self::assertSame($index < 4 ? 'database' : $before[$index]['type'], $row['type']);
            unset($row['type'], $before[$index]['type']);
            self::assertSame($before[$index], $row);
        }
        self::assertSame([20261011000700], $runner->migrate($configuration)->executed);
        $merged = Db::table('file_assets')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        foreach ($merged as $index => $row) {
            self::assertSame($index < 4 ? 'other' : $after[$index]['type'], $row['type']);
            unset($row['type'], $after[$index]['type']);
            self::assertSame($after[$index], $row);
        }
        self::assertSame([], $runner->migrate($configuration)->executed);
        if (Db::connection()->getDriverName() === 'mysql') {
            $column = Db::selectOne("SELECT COLUMN_COMMENT AS comment, IS_NULLABLE AS nullable, CHARACTER_MAXIMUM_LENGTH AS size FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orm_file_assets' AND COLUMN_NAME='type'");
            self::assertStringNotContainsString('database', $column->comment);
            self::assertStringContainsString('other其他', $column->comment);
            self::assertSame('NO', $column->nullable);
            self::assertSame(16, (int) $column->size);
        }
    }
}
