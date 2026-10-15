<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_update\infrastructure\PdoSchemaInspector;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实升级保留旧消息内容，冲突时保证原凭据不被覆盖。 */
final class NotificationRobotMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261007000200;
        $this->initializeDatabase();
    }

    public function testMigratesSharedCredentialsOnceAndKeepsTemplatesAndState(): void
    {
        $cipher = new SodiumSensitiveDataCipher((string) getenv('APP_KEY'));
        $config = ['email' => ['enabled' => false, 'title' => 'TEST_TITLE', 'body' => 'TEST_CONTENT'],
            'dingtalk' => ['enabled' => true, 'title' => '订单', 'body' => '{{order_no}}', 'webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=PRIVATE_TOKEN', 'sign_secret' => 'PRIVATE_SECRET']];
        $this->seed('order_paid', $cipher->encrypt(json_encode($config, JSON_THROW_ON_ERROR)));
        $this->seed('ticket_created', $cipher->encrypt(json_encode($config, JSON_THROW_ON_ERROR)));
        [$runner, $configuration] = $this->runner();
        self::assertSame([20261007000300], $runner->migrate($configuration)->executed);
        $raw = Db::table('system_settings')->where('setting_key', 'notifications.robots_encrypted')->first();
        self::assertSame(0, (int) $raw->is_public);
        $shared = json_decode($cipher->decrypt($raw->setting_value), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('PRIVATE_SECRET', $shared['robots']['dingtalk']['sign_secret']);
        self::assertSame(1, $shared['revision']);
        foreach (Db::table('message_configurations')->get() as $row) {
            $new = json_decode($cipher->decrypt($row->configuration_encrypted), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($config['email'], $new['email']);
            self::assertTrue($new['dingtalk']['enabled']);
            self::assertSame('{{order_no}}', $new['dingtalk']['body']);
            self::assertArrayNotHasKey('webhook_url', $new['dingtalk']);
            self::assertArrayNotHasKey('sign_secret', $new['dingtalk']);
            self::assertSame(2, (int) $row->revision);
        }
        Db::table('migration_versions')->where('version', 20261007000300)->delete();
        $runner->migrate($configuration);
        self::assertSame(1, Db::table('system_setting_categories')->where('code', 'notifications')->count());
        self::assertSame($raw->setting_value, Db::table('system_settings')->where('id', $raw->id)->value('setting_value'));
    }

    public function testDifferentLegacyRobotsFailWithoutDeletingOrChangingCredentials(): void
    {
        $cipher = new SodiumSensitiveDataCipher((string) getenv('APP_KEY'));
        foreach (['order_paid' => 'TOKEN_ONE', 'ticket_created' => 'TOKEN_TWO'] as $code => $token) {
            $this->seed($code, $cipher->encrypt(json_encode(['dingtalk' => ['webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=' . $token, 'sign_secret' => '']], JSON_THROW_ON_ERROR)));
        }
        $before = Db::table('message_configurations')->pluck('configuration_encrypted', 'id')->all();
        [$runner, $configuration] = $this->runner();
        try { $runner->migrate($configuration); self::fail('不能擅自选择不同的机器人'); }
        catch (\app\modules\system_update\exception\SystemUpdateException $error) {
            self::assertStringContainsString('不同的机器人配置', $error->getPrevious()->getMessage());
        }
        self::assertSame($before, Db::table('message_configurations')->pluck('configuration_encrypted', 'id')->all());
        self::assertSame(0, Db::table('system_setting_categories')->where('code', 'notifications')->count());
        self::assertSame(0, Db::table('migration_versions')->where('version', 20261007000300)->count());
    }

    private function seed(string $code, string $payload): void
    {
        $now = '2026-10-07 08:00:00';
        Db::table('message_configurations')->insert(['audience' => 'admin', 'message_type' => $code, 'configuration_encrypted' => $payload,
            'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function runner(): array
    {
        return [new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector()),
            MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig())];
    }
}
