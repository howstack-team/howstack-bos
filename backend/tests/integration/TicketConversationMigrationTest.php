<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PdoSchemaInspector, PhinxCoreMigrationRunner, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 验证历史工单升级、重复执行、自定义前缀及MySQL真实注释。 */
final class TicketConversationMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private createDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261018000500;
        $this->createDatabase();
    }

    public function testUpgradePreservesHistoryAndDocumentsNewColumns(): void
    {
        $now = date('Y-m-d H:i:s'); $member = $this->member();
        $type = $this->row('ticket_types', ['name' => '原类型', 'code' => 'old_type', 'status' => 1, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        $id = $this->row('tickets', ['ticket_no' => 'OLD-TICKET', 'member_id' => $member, 'type_id' => $type, 'subject' => '原主题', 'content' => '原始会话', 'priority' => 'normal', 'status' => 'processing', 'created_at' => $now, 'updated_at' => $now]);
        $this->row('ticket_messages', ['ticket_id' => $id, 'author_type' => 'admin', 'author_id' => 1, 'content' => '原回复', 'created_at' => $now]);
        $config = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $runner->migrate($config);
            $row = Db::table('tickets')->where('id', $id)->first();
            self::assertSame('原始会话', $row->content); self::assertSame('processing', $row->status); self::assertNull($row->service_id);
            self::assertSame('原回复', Db::table('ticket_messages')->value('content')); self::assertSame(0, Db::table('ticket_commands')->count());
        }
        if (Db::connection()->getDriverName() === 'mysql') {
            foreach (['tickets' => ['service_id'], 'ticket_commands' => ['id', 'actor_type', 'actor_id', 'request_key', 'action', 'payload_hash', 'ticket_id', 'message_id', 'created_at']] as $table => $fields) {
                $columns = Db::select('SELECT COLUMN_NAME AS name,COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$config->tablePrefix . $table]);
                foreach ($fields as $field) {
                    $column = array_values(array_filter($columns, static fn (object $row): bool => $row->name === $field));
                    self::assertCount(1, $column); self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column[0]->comment);
                }
            }
            $comment = Db::selectOne('SELECT TABLE_COMMENT AS comment FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$config->tablePrefix . 'ticket_commands']);
            self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $comment->comment);
        }
    }
}
