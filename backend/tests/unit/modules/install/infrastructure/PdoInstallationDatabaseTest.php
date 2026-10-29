<?php

declare(strict_types=1);

namespace tests\unit\modules\install\infrastructure;

use app\modules\install\domain\InstallationConfiguration;
use app\modules\install\exception\InstallationException;
use app\modules\install\infrastructure\BuiltinChannelDependencyInspector;
use app\modules\install\infrastructure\PdoConnectionFactory;
use app\modules\install\infrastructure\PdoInstallationDatabase;
use app\modules\system_update\contract\CoreMigrationRunnerInterface;
use app\modules\system_update\contract\SystemMigrationLockInterface;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\domain\MigrationResult;
use app\modules\system_update\domain\MigrationStatus;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * 验证安装迁移的依赖前置检查与错误边界，迁移和锁均使用替身而不连接数据库。
 */
final class PdoInstallationDatabaseTest extends TestCase
{
    private string $root;

    /**
     * 创建独立的内置渠道目录。
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/howstack-install-schema-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    /**
     * 删除当前用例创建的文件，保留仓库真实插件和数据库。
     * @return void
     */
    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    /**
     * 缺少渠道发行依赖时，在申请锁和执行任何迁移之前返回状态冲突。
     * @return void
     */
    public function testMissingDependenciesFailBeforeLockOrMigration(): void
    {
        $this->wechatPackage(false);
        $migrations = $this->createMock(CoreMigrationRunnerInterface::class);
        $migrations->expects(self::never())->method('migrate');
        $lock = $this->createMock(SystemMigrationLockInterface::class);
        $lock->expects(self::never())->method('synchronized');
        $database = new PdoInstallationDatabase(
            new PdoConnectionFactory(),
            $migrations,
            $lock,
            new BuiltinChannelDependencyInspector($this->root),
        );

        try {
            $database->createSchema($this->configuration());
            self::fail('缺少微信支付生产依赖时必须阻止迁移');
        } catch (InstallationException $exception) {
            self::assertSame(409, $exception->httpStatus());
            self::assertStringContainsString('wechat_native', $exception->getMessage());
            self::assertStringNotContainsString($this->root, $exception->getMessage());
            self::assertStringNotContainsString('fixture-db-secret', $exception->getMessage());
        }
    }

    /**
     * 完整生产依赖通过后，迁移在锁内接收安装表单中的连接配置。
     * @return void
     */
    public function testValidDependenciesAllowMigrationInsideLock(): void
    {
        $this->wechatPackage(true);
        $configuration = $this->configuration();
        $insideLock = false;
        $migrations = $this->createMock(CoreMigrationRunnerInterface::class);
        $migrations->expects(self::once())
            ->method('migrate')
            ->willReturnCallback(function (MigrationDatabaseConfiguration $actual) use ($configuration, &$insideLock): MigrationResult {
                self::assertTrue($insideLock);
                self::assertSame('mysql', $actual->adapter);
                self::assertSame($configuration->database->mysqlDatabase, $actual->database);
                self::assertSame($configuration->database->tablePrefix, $actual->tablePrefix);
                self::assertSame($configuration->database->mysqlHost, $actual->host);
                self::assertSame($configuration->database->mysqlPort, $actual->port);
                self::assertSame($configuration->database->mysqlUsername, $actual->username);
                self::assertSame($configuration->database->mysqlPassword, $actual->password);

                return new MigrationResult([], new MigrationStatus([], [], [], 0), '');
            });
        $lock = $this->createMock(SystemMigrationLockInterface::class);
        $lock->expects(self::once())
            ->method('synchronized')
            ->willReturnCallback(function (callable $callback) use (&$insideLock): mixed {
                $insideLock = true;
                try {
                    return $callback();
                } finally {
                    $insideLock = false;
                }
            });
        $database = new PdoInstallationDatabase(
            new PdoConnectionFactory(),
            $migrations,
            $lock,
            new BuiltinChannelDependencyInspector($this->root),
        );

        $database->createSchema($configuration);

        self::assertFalse($insideLock);
    }

    /**
     * 未知迁移错误保留原异常，用户提示不泄露 SQL、密码、路径或武断归因为权限。
     * @return void
     */
    public function testUnexpectedMigrationFailurePreservesPreviousWithoutLeakingSecrets(): void
    {
        $this->wechatPackage(true);
        $previous = new RuntimeException('SELECT secret FROM fixture_table; password=fixture-db-secret; /private/fixture-secret.sql');
        $migrations = $this->createMock(CoreMigrationRunnerInterface::class);
        $migrations->expects(self::once())->method('migrate')->willThrowException($previous);
        $lock = $this->createMock(SystemMigrationLockInterface::class);
        $lock->expects(self::once())
            ->method('synchronized')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $database = new PdoInstallationDatabase(
            new PdoConnectionFactory(),
            $migrations,
            $lock,
            new BuiltinChannelDependencyInspector($this->root),
        );

        try {
            $database->createSchema($this->configuration());
            self::fail('迁移异常必须转换为安装异常');
        } catch (InstallationException $exception) {
            self::assertSame(500, $exception->httpStatus());
            self::assertSame($previous, $exception->getPrevious());
            self::assertNotSame('', $exception->getMessage());
            self::assertStringNotContainsString('请检查数据库权限后重试', $exception->getMessage());
            self::assertStringNotContainsString('SELECT secret', $exception->getMessage());
            self::assertStringNotContainsString('fixture-db-secret', $exception->getMessage());
            self::assertStringNotContainsString('/private/fixture-secret.sql', $exception->getMessage());
            self::assertStringNotContainsString($this->root, $exception->getMessage());
        }
    }

    /**
     * 构造合法但仅供迁移替身读取的安装配置。
     * @return InstallationConfiguration 安装配置
     */
    private function configuration(): InstallationConfiguration
    {
        return InstallationConfiguration::fromArray([
            'agreement_agreed' => true,
            'mysql' => [
                'host' => 'db.install-fixture.invalid',
                'port' => 3307,
                'database' => 'fixture_installation',
                'username' => 'fixture_installer',
                'password' => 'fixture-db-secret',
            ],
            'redis' => [
                'host' => 'redis.install-fixture.invalid',
                'port' => 6379,
                'password' => '',
                'database' => 0,
            ],
            'system' => [
                'site_title' => '安装测试站点',
                'site_url' => 'https://installation.example.com',
                'admin_path' => 'manage-center',
            ],
            'admin' => [
                'username' => 'fixture_admin',
                'nickname' => '测试管理员',
                'password' => 'Fixture-Aa1!password',
            ],
        ]);
    }

    /**
     * 创建最小微信支付依赖包，可选择是否交付生产 vendor。
     * @param bool $withVendor 是否交付生产依赖
     * @return void
     */
    private function wechatPackage(bool $withVendor): void
    {
        $folder = $this->root . '/payment/wechat_native';
        mkdir($folder, 0700, true);
        $dependency = ['name' => 'wechatpay/wechatpay', 'version' => '1.5.0', 'type' => 'library'];
        file_put_contents($folder . '/composer.json', json_encode([
            'require' => ['wechatpay/wechatpay' => '^1.5'],
            'config' => ['allow-plugins' => false],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($folder . '/composer.lock', json_encode(['packages' => [$dependency]], JSON_THROW_ON_ERROR));
        if ($withVendor) {
            mkdir($folder . '/vendor/composer', 0700, true);
            file_put_contents($folder . '/vendor/composer/installed.json', json_encode([
                'packages' => [$dependency],
                'dev' => false,
            ], JSON_THROW_ON_ERROR));
            file_put_contents($folder . '/vendor/autoload.php', '<?php return null;');
        }
    }
}
