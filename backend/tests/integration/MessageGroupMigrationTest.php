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

final class MessageGroupMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private setUpDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261007000600;
        $this->setUpDatabase();
    }

    public function testExistingSnapshotsReadStateAndRequestIdentifiersSurviveUpgrade(): void
    {
        $now = '2026-10-07 12:00:00';
        $message = Db::table('messages')->insertGetId(['event_key' => str_repeat('a', 64), 'scene' => 'order_paid', 'purpose' => 'notification', 'business_type' => 'order', 'business_id' => 9, 'created_at' => $now]);
        $recipient = Db::table('message_recipients')->insertGetId(['message_id' => $message, 'audience' => 'customer', 'account_id' => 1, 'target_key' => '1', 'read_at' => $now]);
        Db::table('message_deliveries')->insert(['recipient_id' => $recipient, 'request_key' => '00000000-0000-0000-0000-000000000009', 'channel' => 'message', 'payload_encrypted' => 'old-ciphertext-preserve', 'status' => 'delivered', 'attempts' => 1, 'next_attempt_at' => $now, 'queue_after' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $before = (array) Db::table('message_deliveries')->first();
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner->migrate($configuration); $runner->migrate($configuration);
        self::assertSame($before + ['channel_name' => ''], (array) Db::table('message_deliveries')->first());
        $after = Db::table('message_recipients')->first();
        self::assertSame('legacy', $after->group_key);
        self::assertSame('', $after->group_name);
        self::assertSame($now, $after->read_at);
        self::assertSame('1', $after->target_key);
        if (getenv('HOWSTACK_MYSQL_TEST') === '1') {
            $connection = Db::connection();
            $columns = $connection->select("SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN ('group_key', 'group_name')", [$connection->getDatabaseName(), $connection->getTablePrefix() . 'message_recipients']);
            self::assertCount(2, $columns);
            foreach ($columns as $column) self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->COLUMN_COMMENT);
        }
    }
}
