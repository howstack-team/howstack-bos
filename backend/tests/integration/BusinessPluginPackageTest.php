<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use PHPUnit\Framework\TestCase;

/** 静态包拒绝未知声明和不支持类别，错误不得拖垮其他包。 */
final class BusinessPluginPackageTest extends TestCase
{
    private string $root;
    private array $manifest;
    private array $schema;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/fixtures/sample_provider/ProviderAdapter.php';
        $this->root = sys_get_temp_dir() . '/howstack-package-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/provider/sample_provider', 0700, true);
        copy(dirname(__DIR__) . '/fixtures/sample_provider/ProviderAdapter.php', $this->root . '/provider/sample_provider/ProviderAdapter.php');
        $this->manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->manifest['id'] = 'sample_provider';
        $this->schema = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/configuration.schema.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testUnknownTopLevelFieldIsRejected(): void
    {
        $this->manifest['execute'] = 'shell';
        $registry = $this->registry();
        self::assertSame([], $registry->packages());
        self::assertStringContainsString('清单结构', $registry->failures()[0]['message']);
    }

    public function testRootEntryIsRequiredAndOldApiLayoutIsNotLoaded(): void
    {
        $registry = $this->registry();
        self::assertSame('plugins\\provider\\sample_provider\\ProviderAdapter', $registry->packages()['sample_provider']['entry']);
        $folder = $this->root . '/provider/sample_provider';
        mkdir($folder . '/api', 0700);
        rename($folder . '/ProviderAdapter.php', $folder . '/api/ProviderAdapter.php');
        self::assertSame([], $this->registry()->packages());
        self::assertStringContainsString('入口缺失', $this->registry()->failures()[0]['message']);
    }

    public function testRootEntryAndAdditionalRootPhpKeepDependencyChecks(): void
    {
        $folder = $this->root . '/provider/sample_provider';
        $this->registry();
        file_put_contents($folder . '/ProviderAdapter.php', '<?php namespace plugins\\provider\\sample_provider; use support\\Db;');
        self::assertSame([], $this->registry()->packages());
        self::assertStringContainsString('供应 SDK v1', $this->registry()->failures()[0]['message']);
        copy(dirname(__DIR__) . '/fixtures/sample_provider/ProviderAdapter.php', $folder . '/ProviderAdapter.php');
        file_put_contents($folder . '/Helper.php', '<?php curl_init();');
        self::assertSame([], $this->registry()->packages());
        self::assertStringContainsString('供应 SDK v1', $this->registry()->failures()[0]['message']);
    }

    public function testRootEntryNamespaceMustMatchItsOwnPluginIdentity(): void
    {
        $folder = $this->root . '/provider/sample_provider';
        $this->registry();
        file_put_contents($folder . '/ProviderAdapter.php', '<?php namespace plugins\\provider\\sample_provider\\api;');
        self::assertSame([], $this->registry()->packages());
        self::assertStringContainsString('命名空间', $this->registry()->failures()[0]['message']);
    }

    public function testOnlyTheUnifiedV1ProtocolIsAccepted(): void
    {
        $registry = $this->registry();
        self::assertSame('1.0', $registry->packages()['sample_provider']['requires']['provider_sdk']);

        $this->manifest['requires']['provider_sdk'] = '2.0';
        $registry = $this->registry();
        self::assertSame([], $registry->packages());
        self::assertStringContainsString('运行契约', $registry->failures()[0]['message']);
    }

    public function testV1RejectsCoreDependenciesAndDirectExternalIo(): void
    {
        $folder = $this->root . '/provider/sample_provider/app';
        mkdir($folder, 0700);
        foreach ([
            'use app\\modules\\provider\\contract\\ProviderAdapterInterface;',
            'curl_init();',
        ] as $code) {
            file_put_contents($folder . '/Forbidden.php', '<?php ' . $code);
            $registry = $this->registry();
            self::assertSame([], $registry->packages());
            self::assertStringContainsString('供应 SDK v1', $registry->failures()[0]['message']);
        }
    }

    public function testProviderSdkReferencesDoNotUseAPackageNameWhitelist(): void
    {
        $folder = $this->root . '/provider/sample_provider/app';
        mkdir($folder, 0700);
        foreach ([
            'use GuzzleHttp\\Client;',
            'use PHPMailer\\PHPMailer\\PHPMailer;',
            'use ExampleVendor\\Client;',
        ] as $code) {
            file_put_contents($folder . '/Sdk.php', '<?php ' . $code);
            self::assertArrayHasKey('sample_provider', $this->registry()->packages());
        }
    }

    public function testInvalidSchemaDefaultAndRemoteReferencesAreRejected(): void
    {
        $this->schema['fields'][] = ['key' => 'retries', 'label' => '次数', 'type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 3, 'default' => 8];
        self::assertStringContainsString('默认值', $this->registry()->failures()[0]['message']);
        $this->schema['$ref'] = 'https://example.test/schema';
        self::assertSame([], $this->registry()->packages());
    }

    public function testInvalidPluginCannotHideHealthyPluginAndDuplicateIdIsRemoved(): void
    {
        $registry = $this->registry();
        self::assertCount(1, $registry->packages());
        mkdir($this->root . '/payment/sample_provider', 0700, true);
        file_put_contents($this->root . '/payment/sample_provider/plugin.json', '{}');
        $registry = $this->registry();
        self::assertSame([], $registry->packages());
        self::assertStringContainsString('重复', $registry->failures()[0]['message']);
    }

    public function testSecretsAndSymlinksCannotEnterPackage(): void
    {
        file_put_contents($this->root . '/provider/sample_provider/.env', 'TEST_SECRET=never-load');
        self::assertSame([], $this->registry()->packages());
        unlink($this->root . '/provider/sample_provider/.env');
        symlink($this->root . '/provider/sample_provider/plugin.json', $this->root . '/provider/sample_provider/link.json');
        self::assertSame([], $this->registry()->packages());
    }

    public function testRuntimeExceptionIsReturnedAsSafeDiagnostic(): void
    {
        $this->manifest['version'] = ['fake-secret'];
        $registry = $this->registry();
        self::assertSame([], $registry->packages());
        self::assertStringNotContainsString('fake-secret', $registry->failures()[0]['message']);
        self::assertStringNotContainsString($this->root, $registry->failures()[0]['message']);
    }

    public function testUnreviewedCapabilitiesAndRoutesAreRejected(): void
    {
        $this->manifest['capabilities']['cloud_server.provision'] = true;
        self::assertStringContainsString('生产', $this->registry()->failures()[0]['message']);
        $this->manifest['capabilities']['cloud_server.provision'] = false;
        $this->manifest['frontend'] = [['url' => '/arbitrary.js']];
        self::assertSame([], $this->registry()->packages());
    }

    public function testConfigurationDtoCannotExposeSecretsThroughSerialization(): void
    {
        $configuration = new \app\shared\plugin\dto\Configuration(['password' => 'do-not-log']);
        self::assertSame('{"redacted":true}', json_encode($configuration, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('do-not-log', print_r($configuration->__debugInfo(), true));
        $this->expectException(\LogicException::class);
        serialize($configuration);
    }

    public function testMailContractAllowsClientsButRejectsExtraCapabilitiesAndCoreDependencies(): void
    {
        $folder = $this->root . '/mail/smtp';
        mkdir($folder, 0700, true);
        mkdir($folder . '/app', 0700);
        foreach (['plugin.json', 'configuration.schema.json', 'MailAdapter.php', 'app/SmtpConfiguration.php', 'app/TrackedSmtp.php'] as $file) {
            copy(dirname(__DIR__, 2) . '/plugins/mail/smtp/' . $file, $folder . '/' . $file);
        }
        self::assertArrayHasKey('smtp', $this->registry()->packages());
        $manifest = json_decode(file_get_contents($folder . '/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['capabilities']['mail.attachments'] = true;
        file_put_contents($folder . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('smtp', $this->registry()->packages());
        unset($manifest['capabilities']['mail.attachments']);
        $manifest['requires']['mail_sdk'] = '2.0';
        file_put_contents($folder . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('smtp', $this->registry()->packages());
        $manifest['requires']['mail_sdk'] = '1.0';
        file_put_contents($folder . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($folder . '/app/Client.php', '<?php use PHPMailer\\PHPMailer\\PHPMailer; use GuzzleHttp\\Client;');
        self::assertArrayHasKey('smtp', $this->registry()->packages());
        foreach (['use app\\modules\\channel\\infrastructure\\DatabaseChannelRepository;', 'use support\\Db;',
            'use plugins\\provider\\sample_provider\\ProviderAdapter;', 'curl_init();', 'getenv("SECRET");'] as $code) {
            file_put_contents($folder . '/app/Forbidden.php', '<?php ' . $code);
            $registry = $this->registry();
            self::assertArrayNotHasKey('smtp', $registry->packages());
            self::assertArrayHasKey('sample_provider', $registry->packages());
        }
    }

    public function testLocalDependenciesAreIncludedInPackageHashWithoutRunningAutoload(): void
    {
        $folder = $this->root . '/mail/smtp';
        $source = dirname(__DIR__, 2) . '/plugins/mail/smtp';
        mkdir($folder, 0700, true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            $target = $folder . substr($file->getPathname(), strlen($source));
            if ($file->isDir()) {
                mkdir($target, 0700);
            } else {
                copy($file->getPathname(), $target);
            }
        }
        $marker = $folder . '/autoload-executed';
        file_put_contents($folder . '/vendor/autoload.php', '<?php touch(' . var_export($marker, true) . ');');
        $before = $this->registry()->packages()['smtp']['package_hash'];
        self::assertFileDoesNotExist($marker);
        file_put_contents($folder . '/vendor/phpmailer/phpmailer/src/PHPMailer.php', "\n// changed SDK", FILE_APPEND);
        $after = $this->registry()->packages()['smtp']['package_hash'];
        self::assertNotSame($before, $after);
        self::assertFileDoesNotExist($marker);
        unlink($folder . '/composer.lock');
        self::assertArrayNotHasKey('smtp', $this->registry()->packages());
    }

    public function testFinderMetadataDoesNotChangeRuntimePackageHash(): void
    {
        $before = $this->registry()->packages()['sample_provider']['package_hash'];
        file_put_contents($this->root . '/provider/sample_provider/.DS_Store', 'Finder metadata');
        self::assertSame($before, $this->registry()->packages()['sample_provider']['package_hash']);
        file_put_contents($this->root . '/provider/sample_provider/.DS_Store', 'Updated Finder metadata');
        self::assertSame($before, $this->registry()->packages()['sample_provider']['package_hash']);
        unlink($this->root . '/provider/sample_provider/.DS_Store');
        symlink($this->root . '/provider/sample_provider/plugin.json', $this->root . '/provider/sample_provider/.DS_Store');
        self::assertArrayNotHasKey('sample_provider', $this->registry()->packages());
    }

    public function testHttpsMailPluginReceivesItsOwnConfigurationWithoutSmtpFields(): void
    {
        require_once dirname(__DIR__) . '/fixtures/sample_mail/MailAdapter.php';
        $folder = $this->root . '/mail/sample_mail';
        mkdir($folder, 0700, true);
        copy(dirname(__DIR__) . '/fixtures/sample_mail/MailAdapter.php', $folder . '/MailAdapter.php');
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/mail/smtp/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['id'] = 'sample_mail';
        $manifest['network'] = ['transport' => 'https'];
        file_put_contents($folder . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $schema = ['schema_version' => '1.0', 'scope' => 'connection', 'fields' => [
            ['key' => 'api_url', 'label' => '平台地址', 'type' => 'url', 'required' => true, 'max_length' => 512],
            ['key' => 'api_key', 'label' => '平台密钥', 'type' => 'password', 'required' => true, 'max_length' => 128],
        ]];
        file_put_contents($folder . '/configuration.schema.json', json_encode($schema, JSON_THROW_ON_ERROR));
        $packages = $this->registry();
        self::assertSame([], $packages->failures());
        self::assertArrayHasKey('sample_mail', $packages->packages());
        $platforms = $this->createStub(\app\modules\channel\contract\ChannelPlatformRegistryInterface::class);
        $platforms->method('definition')->willReturn(['id' => 'sample_mail']);
        $executor = new \app\modules\channel\infrastructure\mail\InstalledMailAdapterExecutor($packages, $platforms);
        $message = new \app\shared\plugin\dto\mail\MailMessage('12345678-1234-1234-1234-123456789abc', 'receiver@example.test', 'API 邮件', '内容');
        $configuration = new \app\shared\plugin\dto\Configuration(['api_url' => 'https://mail.example.test/send', 'api_key' => 'fixture-key']);
        self::assertSame('accepted', $executor->send('sample_mail', $configuration, $message)->status);
        $manifest['network'] = ['transport' => 'shell'];
        file_put_contents($folder . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('sample_mail', $this->registry()->packages());
    }

    private function registry(): LocalPluginPackageRegistry
    {
        file_put_contents($this->root . '/provider/sample_provider/plugin.json', json_encode($this->manifest, JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/provider/sample_provider/configuration.schema.json', json_encode($this->schema, JSON_THROW_ON_ERROR));
        return new LocalPluginPackageRegistry($this->root, '0.1.0');
    }
}
