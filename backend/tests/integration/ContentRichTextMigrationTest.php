<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use app\modules\content\infrastructure\DatabaseContentRepository;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class ContentRichTextMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261007000300;
        $this->initializeDatabase();
    }

    public function testUpgradePreservesMarkdownAndSupportsHtmlWithCommentsAndRetry(): void
    {
        $now = '2026-10-07 10:00:00';
        $category = Db::table('content_categories')->insertGetId(['kind' => 'news', 'name' => '公告', 'parent_id' => null, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $id = Db::table('content_entries')->insertGetId(['kind' => 'news', 'category_id' => $category, 'title' => '历史公告', 'body' => '# 不改变正文', 'created_at' => $now, 'updated_at' => $now]);
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $config = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertContains(20261007000400, $runner->migrate($config)->executed);
        $repository = new DatabaseContentRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $old = $repository->find('news', false, $id);
        self::assertSame('# 不改变正文', $old['body']);
        self::assertSame('markdown', $old['body_format']);
        $repository->save(false, $id, ['body' => '<p>新正文</p>', 'body_format' => 'html']);
        self::assertSame('html', $repository->find('news', false, $id)['body_format']);
        $long = str_repeat('中文正文', 20000);
        $repository->save(false, $id, ['body' => $long]);
        self::assertSame($long, $repository->find('news', false, $id)['body']);
        $repository->save(false, $id, ['body' => '<p>新正文</p>']);
        $images = new \app\modules\content\infrastructure\DatabaseContentImageRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $imageId = str_repeat('a', 32);
        $images->reserve(['id' => $imageId, 'original_name'=>'test.png', 'extension'=>'png','type'=>'image','namespace'=>'content','visibility'=>'public','origin'=>'news', 'channel_id' => 3, 'channel_revision' => 2, 'object_key' => 'content/images/test.png',
            'mime_type' => 'image/png', 'byte_size' => 68, 'created_by' => 1, 'status' => 'pending', 'created_at' => $now]);
        self::assertSame('pending', $images->find($imageId)['status']);
        $images->mark($imageId, 'stored');
        self::assertSame('stored', $images->find($imageId)['status']);
        self::assertSame(2, $images->find($imageId)['channel_revision']);
        if (Db::connection()->getDriverName() === 'mysql') {
            foreach (['content_entries' => ['body_format'], 'file_assets' => ['id', 'channel_id', 'channel_revision', 'object_key', 'mime_type', 'byte_size', 'created_by', 'status', 'created_at']] as $table => $fields) {
                foreach ($fields as $field) {
                    $row = Db::selectOne('SELECT COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['orm_' . $table, $field]);
                    self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $row->comment);
                }
            }
        }
        Db::table('migration_versions')->where('version', 20261007000400)->delete();
        $runner->migrate($config);
        self::assertSame('<p>新正文</p>', $repository->find('news', false, $id)['body']);
    }
}
