<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\ManageChannelsService;
use app\modules\channel\application\mail\SendMailService;
use app\modules\channel\infrastructure\DatabaseChannelRepository;
use app\modules\channel\infrastructure\InstalledChannelPlatformRegistry;
use app\modules\channel\infrastructure\mail\DatabaseMailDeliveryRepository;
use app\modules\channel\infrastructure\mail\InstalledMailAdapterExecutor;
use plugins\mail\smtp\MailAdapter;
use app\modules\channel\validation\ChannelValidator;
use app\modules\plugin\infrastructure\DatabasePluginInstallationRepository;
use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use app\modules\plugin\infrastructure\PluginDependencyLoader;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use plugins\mail\smtp\app\SmtpConfiguration;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

require_once dirname(__DIR__) . '/support/LocalSmtpServer.php';

/** 真实 PHPMailer、SMTP 套接字与隔离数据库，不向外部邮箱投递。 */
final class SmtpMailChannelTest extends TestCase
{
    use IsolatedBusinessDatabase {
        setUp as private setUpDatabase;
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        PluginDependencyLoader::load(dirname(__DIR__, 2) . '/plugins/mail/smtp', 'plugins\\mail\\smtp\\');
    }

    public function testBuiltInConfigurationEncryptionAndAuthenticatedSendAreIdempotent(): void
    {
        $server = new \tests\support\LocalSmtpServer();
        [$manage, $send, $repo, $actor] = $this->fixture();
        $row = $manage->save($actor, 'mail', null, $this->input($server->port));
        self::assertTrue($row['configured_secrets']->password);
        self::assertObjectNotHasProperty('password', $row['configuration']);
        self::assertStringNotContainsString('fixture-secret', Db::table('channel_connections')->value('configuration_encrypted'));
        self::assertSame('fixture-secret', $repo->configuration('mail', $row['id'])['password']);
        $input = ['recipient' => 'receiver@example.test', 'request_key' => $this->key(), 'revision' => $row['revision']];
        $first = $send->test($actor, $row['id'], $input);
        self::assertSame('accepted', $first['status']);
        self::assertSame($first, $send->test($actor, $row['id'], $input));
        $messages = $server->messages();
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]['authenticated']);
        self::assertStringContainsString('sender@example.test', $messages[0]['sender']);
        self::assertStringContainsString('receiver@example.test', $messages[0]['recipient']);
        self::assertStringContainsString($first['message_id'], $messages[0]['message']);
        self::assertStringContainsString(base64_encode('SMTP 邮件通道测试'), $messages[0]['message']);
        self::assertSame('accepted', Db::table('mail_delivery_attempts')->value('status'));
        self::assertStringNotContainsString('receiver@example.test', json_encode(Db::table('mail_delivery_attempts')->first()));
        $this->reject(409, fn () => $send->test($actor, $row['id'], [...$input, 'recipient' => 'different@example.test']));
        $this->reject(429, fn () => $send->test($actor, $row['id'], [...$input, 'request_key' => $this->key()]));
    }

    public function testDisabledChannelAllowsTestButCannotSendBusinessOrDefault(): void
    {
        $server = new \tests\support\LocalSmtpServer();
        [$manage, $send, , $actor] = $this->fixture();
        $row = $manage->save($actor, 'mail', null, $this->input($server->port));
        $message = new MailMessage($this->key(), 'receiver@example.test', '业务通知', '纯文本通知', '<p>HTML 通知</p>');
        $this->reject(409, fn () => $send->send($row['id'], $row['revision'], $message));
        $this->reject(409, fn () => $send->sendDefault($message));
        self::assertCount(0, $server->messages());
        $input = $this->input($server->port);
        $input['status'] = 1;
        $input['is_default'] = true;
        $input['revision'] = $row['revision'];
        $row = $manage->save($actor, 'mail', $row['id'], $input);
        $this->reject(409, fn () => $send->send($row['id'], $row['revision'] - 1, $message));
        self::assertSame('accepted', $send->sendDefault($message)->status);
        self::assertSame('accepted', $send->sendDefault($message)->status);
        self::assertCount(1, $server->messages());
        self::assertStringContainsString('multipart/alternative', $server->messages()[0]['message']);
    }

    public function testPermissionsAndCategoryAreCheckedBeforeAnySend(): void
    {
        $server = new \tests\support\LocalSmtpServer();
        [$manage, $send, , $actor] = $this->fixture();
        $row = $manage->save($actor, 'mail', null, $this->input($server->port));
        $input = ['recipient' => 'receiver@example.test', 'request_key' => $this->key(), 'revision' => $row['revision']];
        $viewer = new AdminAccount(1, 'viewer', '查看人', 'hidden', 1, null);
        $this->reject(403, fn () => $send->test($viewer, $row['id'], $input));
        $this->reject(422, fn () => $send->test($actor, $row['id'], [...$input, 'subject' => '任意内容']));
        $this->reject(422, fn () => $send->test($actor, $row['id'], [...$input, 'recipient' => "a@example.test\r\nBcc:b@example.test"]));
        self::assertCount(0, $server->messages());
        self::assertSame(0, Db::table('mail_delivery_attempts')->count());
    }

    public function testAuthenticationFailureAndDataRejectionAreSafeFailures(): void
    {
        foreach (['reject_auth', 'reject_data'] as $mode) {
            $server = new \tests\support\LocalSmtpServer($mode);
            $config = $this->input($server->port)['configuration'];
            $result = (new MailAdapter())->send(new MailMessage($this->key(), 'receiver@example.test', '测试', '内容'), new Configuration($config));
            self::assertSame('failed', $result->status);
            self::assertStringNotContainsString('fixture-secret', json_encode($result->toArray()));
            self::assertCount($mode === 'reject_auth' ? 0 : 1, $server->messages());
        }
    }

    public function testDisconnectAfterDataIsUnknownAndSameRequestDoesNotSendAgain(): void
    {
        $server = new \tests\support\LocalSmtpServer('drop_data');
        [$manage, $send, , $actor] = $this->fixture();
        $row = $manage->save($actor, 'mail', null, $this->input($server->port));
        $input = ['recipient' => 'receiver@example.test', 'request_key' => $this->key(), 'revision' => $row['revision']];
        self::assertSame('unknown', $send->test($actor, $row['id'], $input)['status']);
        self::assertSame('unknown', $send->test($actor, $row['id'], $input)['status']);
        self::assertCount(1, $server->messages());
        self::assertSame('unknown', Db::table('mail_delivery_attempts')->value('status'));
        Db::table('business_plugin_installations')->where('plugin_id', 'smtp')->update(['state' => 'disabled']);
        self::assertSame('unknown', $send->test($actor, $row['id'], $input)['status']);
        self::assertCount(1, $server->messages());
    }

    public function testSmtpConfigurationRejectsHostInjectionAndInvalidAuthentication(): void
    {
        foreach (['host' => 'smtp.example.test;other.example.test', 'from_email' => "a@example.test\r\nBcc:b@example.test", 'port' => '465', 'password' => '', 'encryption' => 'invalid', 'auth' => 1] as $field => $value) {
            try {
                SmtpConfiguration::normalize(new Configuration([...$this->input(2525)['configuration'], $field => $value]));
                self::fail('无效配置应被拒绝');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $configuration = SmtpConfiguration::normalize(new Configuration([...$this->input(2525)['configuration'], 'auth' => false, 'username' => '', 'password' => '']));
        self::assertFalse($configuration->values['auth']);
    }

    public function testTlsAndStartTlsVerifyTrustedCertificatesAndRejectUntrustedCertificate(): void
    {
        $folder = sys_get_temp_dir() . '/howstack-smtp-tls-' . bin2hex(random_bytes(6));
        mkdir($folder, 0700);
        $cert = $folder . '/certificate.pem';
        $key = $folder . '/key.pem';
        $process = proc_open(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-keyout', $key, '-out', $cert, '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertSame(0, proc_close($process));
        chmod($key, 0600);
        try {
            foreach (['ssl', 'tls'] as $mode) {
                $server = new \tests\support\LocalSmtpServer($mode, $cert, $key);
                $config = [...$this->input($server->port)['configuration'], 'host' => 'localhost', 'encryption' => $mode];
                $message = new MailMessage($this->key(), 'receiver@example.test', 'TLS 测试', '安全连接');
                $failed = (new MailAdapter())->send($message, new Configuration($config));
                self::assertSame('failed', $failed->status);
                self::assertCount(0, $server->messages());
                // openssl.cafile 为启动配置，在独立PHP进程内设置；不修改真实信任库。
                $sender = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'openssl.cafile=' . $cert, dirname(__DIR__) . '/fixtures/smtp/send.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $senderPipes);
                fwrite($senderPipes[0], json_encode(['configuration' => $config, 'key' => $message->key], JSON_THROW_ON_ERROR));
                fclose($senderPipes[0]);
                $sent = json_decode(stream_get_contents($senderPipes[1]), true, flags: JSON_THROW_ON_ERROR);
                fclose($senderPipes[1]);
                self::assertSame(0, proc_close($sender));
                self::assertSame('accepted', $sent['status']);
                self::assertCount(1, $server->messages());
                self::assertTrue($server->messages()[0]['encrypted']);
            }
        } finally {
            unlink($cert);
            unlink($key);
            rmdir($folder);
        }
    }

    public function testNoAuthAndConsecutiveSendsDoNotShareRecipients(): void
    {
        $server = new \tests\support\LocalSmtpServer();
        $configuration = new Configuration([...$this->input($server->port)['configuration'], 'auth' => false, 'username' => '', 'password' => '']);
        $adapter = new MailAdapter();
        foreach (['first@example.test', 'second@example.test'] as $recipient) {
            self::assertSame('accepted', $adapter->send(new MailMessage($this->key(), $recipient, '独立通知', '独立正文'), $configuration)->status);
        }
        self::assertCount(2, $server->messages());
        self::assertFalse($server->messages()[0]['authenticated']);
        self::assertFalse($server->messages()[1]['authenticated']);
        self::assertStringNotContainsString('first@example.test', $server->messages()[1]['message']);
    }

    public function testCrashedSendingRecordNeverAutomaticallyResends(): void
    {
        $server = new \tests\support\LocalSmtpServer();
        [$manage, $send, , $actor] = $this->fixture();
        $row = $manage->save($actor, 'mail', null, $this->input($server->port));
        $input = ['recipient' => 'receiver@example.test', 'request_key' => $this->key(), 'revision' => $row['revision']];
        self::assertSame('accepted', $send->test($actor, $row['id'], $input)['status']);
        Db::table('mail_delivery_attempts')->where('request_key', $input['request_key'])->update(['status' => 'sending']);
        self::assertSame('unknown', $send->test($actor, $row['id'], $input)['status']);
        self::assertCount(1, $server->messages());
    }

    public function testMailDeliverySchemaIncludesChineseCommentsOnEveryField(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('字段注释需要真实 MySQL');
        }
        $fields = Db::select('SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [Db::connection()->getDatabaseName(), 'orm_mail_delivery_attempts']);
        self::assertCount(10, $fields);
        foreach ($fields as $field) {
            self::assertMatchesRegularExpression('/\\p{Han}/u', $field->COLUMN_COMMENT);
        }
    }

    private function fixture(): array
    {
        $transactions = new DatabaseTransactionManager();
        $installations = new DatabasePluginInstallationRepository($transactions);
        $packages = new LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        self::assertSame([], $packages->failures());
        $registry = new InstalledChannelPlatformRegistry($packages, $installations, new Client());
        self::assertCount(3, $registry->definitions('mail'));
        self::assertNotNull($registry->definition('mail', 'smtp'));
        $repo = new DatabaseChannelRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), $transactions, $registry, $installations);
        $accessRepo = $this->createStub(AdminAccessRepositoryInterface::class);
        $accessRepo->method('grantsForAdmin')->willReturn([['permissions' => ['admin.channels.mail.view']]]);
        $access = new AdminAccessControlService($accessRepo);
        $manage = new ManageChannelsService($access, $repo, $registry, new ChannelValidator());
        $send = new SendMailService(new DatabaseMailDeliveryRepository($transactions, $repo, $registry, $installations),
            new InstalledMailAdapterExecutor($packages, $registry), $access, new ChannelValidator());
        $actor = new AdminAccount(1, 'founder', '创始人', 'hidden', 1, null, true);
        return [$manage, $send, $repo, $actor];
    }

    private function input(int $port): array
    {
        return ['name' => '本地 SMTP 测试', 'plugin_id' => 'smtp', 'status' => 0, 'is_default' => false, 'sort_order' => 0, 'description' => '', 'revision' => 0,
            'configuration' => ['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'auth' => true, 'username' => 'fixture-user', 'password' => 'fixture-secret',
                'from_email' => 'sender@example.test', 'from_name' => '云业务通知', 'reply_to' => 'reply@example.test', 'timeout' => 1]];
    }

    private function key(): string
    {
        return '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
    }

    private function reject(int $status, callable $operation): void
    {
        try { $operation(); self::fail('预期拒绝'); }
        catch (BusinessException $error) { self::assertSame($status, $error->httpStatus()); }
    }
}
