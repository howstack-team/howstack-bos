<?php

declare(strict_types=1);

namespace tests\integration\official_platform;

use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\modules\software_maintenance\infrastructure\{DatabaseBackup, LocalReleaseInstaller, VerifiedZipPackage};
use app\modules\system_update\application\SystemMigrationService;
use app\modules\system_update\contract\{CoreMigrationRunnerInterface, SystemMigrationLockInterface, SystemUpdateRepositoryInterface};
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
use ZipArchive;

/** 发布包文件边界及数据库备份验证；测试只访问随机独立目录和数据库。 */
final class ReleasePrecheckTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as databaseSetUp; tearDown as databaseTearDown; }
    private string $root;
    private string $private;
    private const JOB = 'a36efc6b-2f1e-4eae-b775-fc77ff876644';

    protected function setUp(): void
    {
        $this->databaseSetUp();
        $this->root = realpath(sys_get_temp_dir()) . '/howstack-release-' . bin2hex(random_bytes(8));
        $this->private = $this->root . '/private';
        mkdir($this->root, 0700);
    }
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        $this->databaseTearDown();
    }

    public function testEnvironmentSecretsAndBusinessPluginsCannotBeOverwritten(): void
    {
        foreach (['.env', 'plugins/provider/example/plugin.json', 'config/database.php', 'storage/private/identity.json'] as $index => $path) {
            $installer = $this->installer();
            $id = $index === 0 ? self::JOB : sprintf('a36efc6b-2f1e-4eae-b775-fc77ff87664%d', $index);
            $directory = $this->private . '/jobs/' . $id;
            $installer->prepareDirectory($directory);
            $archive = $this->archive($directory, [$path => 'attacker bytes']);
            try { $installer->install(['kind' => 'system', 'request_id' => $id], ['version' => '0.2.0'], $archive, static fn () => null); self::fail('敏感路径不允许被更新'); }
            catch (OfficialPlatformException $error) { self::assertSame('UNSAFE_PACKAGE_PATH', $error->reason); }
            self::assertFileDoesNotExist($this->private . '/maintenance.json');
        }
    }

    public function testExistingMigrationCannotBeRewritten(): void
    {
        mkdir($this->root . '/database/migrations', 0755, true);
        file_put_contents($this->root . '/database/migrations/20261004000100_existing.php', 'existing-customer-migration');
        $installer = $this->installer(); $directory = $this->private . '/jobs/' . self::JOB;
        $installer->prepareDirectory($directory);
        $archive = $this->archive($directory, ['database/migrations/20261004000100_existing.php' => 'changed']);
        $this->expectException(OfficialPlatformException::class);
        try { $installer->install(['kind' => 'system', 'request_id' => self::JOB], ['version' => '0.2.0'], $archive, static fn () => null); }
        finally { self::assertSame('existing-customer-migration', file_get_contents($this->root . '/database/migrations/20261004000100_existing.php')); }
    }

    public function testExtraUnlistedFileIsRejectedBeforeMaintenance(): void
    {
        $installer = $this->installer(); $directory = $this->private . '/jobs/' . self::JOB;
        $installer->prepareDirectory($directory);
        $archive = $this->archive($directory, ['app/safe.php' => 'safe'], ['files/app/unlisted.php' => 'unlisted']);
        try { $installer->install(['kind' => 'system', 'request_id' => self::JOB], ['version' => '0.2.0'], $archive, static fn () => null); self::fail('额外文件不能被解包安装'); }
        catch (OfficialPlatformException $error) { self::assertSame('PACKAGE_PRECHECK_FAILED', $error->reason); }
        self::assertFileDoesNotExist($this->private . '/maintenance.json');
    }

    public function testBackupPreservesEmptyTextUnicodeNullAndEscapedValues(): void
    {
        $pdo = Db::connection()->getPdo();
        $pdo->exec('CREATE TABLE orm_backup_fixture (id INT PRIMARY KEY, empty_value TEXT, note TEXT, missing TEXT)');
        $statement = $pdo->prepare('INSERT INTO orm_backup_fixture VALUES (1, ?, ?, NULL)');
        $statement->execute(['', "中文正文\\'\n多行"]);
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $destination = $this->root . '/backup.sql';
        (new DatabaseBackup(new SystemUpdatePdoFactory()))->create($configuration, $destination);
        self::assertSame(0600, fileperms($destination) & 0777);
        if ($configuration->adapter === 'mysql') {
            $pdo->exec("UPDATE orm_backup_fixture SET empty_value='changed', note='changed', missing='changed'");
            $pdo->exec(file_get_contents($destination));
            $row = $pdo->query('SELECT * FROM orm_backup_fixture')->fetch(\PDO::FETCH_ASSOC);
        } else {
            $backup = new \PDO('sqlite:' . $destination);
            $row = $backup->query('SELECT * FROM orm_backup_fixture')->fetch(\PDO::FETCH_ASSOC);
        }
        self::assertSame('', $row['empty_value']);
        self::assertSame("中文正文\\'\n多行", $row['note']);
        self::assertNull($row['missing']);
    }

    public function testValidCorePackageUsesIsolatedBackupAndFixedMigrationRestartCommands(): void
    {
        mkdir($this->root . '/app', 0755);
        mkdir($this->root . '/config', 0755);
        file_put_contents($this->root . '/.env', 'unchanged-private-settings');
        file_put_contents($this->root . '/app/safe.php', 'original-core-content');
        file_put_contents($this->root . '/config/version.php', "<?php return ['current' => '0.1.0'];");
        file_put_contents($this->root . '/webman', <<<'PHP'
<?php
if ($argv[1] !== 'system:migrate' || $argv[2] !== '--force') exit(1);
file_put_contents(__DIR__ . '/migration-confirmed', 'migrated');
PHP);
        file_put_contents($this->root . '/start.php', <<<'PHP'
<?php
if ($argv[1] !== 'restart' || $argv[2] !== '-d') exit(1);
file_put_contents(__DIR__ . '/restart-confirmed', 'restarted');
PHP);
        $installer = $this->installer();
        $directory = $this->private . '/jobs/' . self::JOB;
        $installer->prepareDirectory($directory);
        $archive = $this->archive($directory, ['app/safe.php' => 'new-core-content']);
        $phases = [];
        $installer->install(['kind' => 'system', 'request_id' => self::JOB], ['version' => '0.2.0'],
            $archive, static function (string $phase) use (&$phases): void { $phases[] = $phase; });
        self::assertSame(['precheck', 'backup', 'installing', 'migrating'], $phases);
        self::assertSame('new-core-content', file_get_contents($this->root . '/app/safe.php'));
        self::assertSame('original-core-content', file_get_contents($directory . '/previous/app/safe.php'));
        self::assertSame('unchanged-private-settings', file_get_contents($this->root . '/.env'));
        self::assertFileExists($directory . '/database.sql');
        self::assertSame(0600, fileperms($directory . '/database.sql') & 0777);
        self::assertFileExists($this->root . '/migration-confirmed');
        self::assertFileExists($this->private . '/maintenance.json');
        $installer->restart();
        self::assertFileExists($this->root . '/restart-confirmed');
    }

    private function installer(): LocalReleaseInstaller
    {
        $runner = $this->createStub(CoreMigrationRunnerInterface::class);
        $repository = $this->createStub(SystemUpdateRepositoryInterface::class);
        $lock = $this->createStub(SystemMigrationLockInterface::class);
        return new LocalReleaseInstaller(new VerifiedZipPackage(), new DatabaseBackup(new SystemUpdatePdoFactory()),
            new SystemMigrationService($runner, $repository, $lock), $this->createStub(PluginInstallationRepositoryInterface::class),
            $this->root, $this->private, '0.1.0', MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig()));
    }
    private function archive(string $directory, array $files, array $extra = []): string
    {
        $files['config/version.php'] = "<?php return ['current' => '0.2.0'];";
        $release = ['schema_version' => 1, 'software_code' => 'howstack-bos', 'from_version' => '0.1.0', 'version' => '0.2.0',
            'minimum_php' => '8.4.0', 'files' => array_map(static fn (string $text): string => hash('sha256', $text), $files), 'remove' => []];
        $path = $directory . '/package.zip'; $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('howstack-release.json', json_encode($release));
        foreach ($files as $name => $text) $zip->addFromString('files/' . $name, $text);
        foreach ($extra as $name => $text) $zip->addFromString($name, $text);
        $zip->close();
        return $path;
    }
}
