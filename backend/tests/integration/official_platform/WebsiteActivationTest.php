<?php

declare(strict_types=1);

namespace tests\integration\official_platform;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\licensing\application\ManageLicenseService;
use app\modules\licensing\domain\{CapabilityCatalog, LicenseEntitlementPolicy};
use app\modules\licensing\infrastructure\SystemLicenseClock;
use app\modules\official_platform\domain\SignedManifestVerifier;
use app\modules\official_platform\infrastructure\{DatabaseOfficialPlatformStateStore, FileInstallationIdentity, HlyqOfficialPlatformClient};
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\modules\software_maintenance\application\{ManageSoftwareUpdatesService, RunInstallationJobService};
use app\modules\software_maintenance\infrastructure\{DatabaseBackup, DatabaseInstallationJobRepository, FileInstallationLock, LocalReleaseInstaller, VerifiedZipPackage};
use app\modules\system_update\application\SystemMigrationService;
use app\modules\system_update\contract\{CoreMigrationRunnerInterface, SystemMigrationLockInterface, SystemUpdateRepositoryInterface};
use app\modules\system_update\domain\{MigrationDatabaseConfiguration, MigrationStatus};
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实隔离官网和原生BOS用例；运行文件/数据库隔离，迁移及重启使用固定探针。 */
final class WebsiteActivationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as databaseSetUp; tearDown as databaseTearDown; }
    private string $root;
    private array $context;

    protected function setUp(): void
    {
        $file = getenv('HOWSTACK_TEST_PLATFORM_CONTEXT');
        if (!$file) { self::markTestSkipped('仅由官网隔离集成测试提供上下文'); }
        $parent = realpath(dirname($file));
        if (!$parent || !str_starts_with(basename($parent), 'howstack-website-test-') || is_link($file)) { throw new \RuntimeException('只允许隔离官网上下文'); }
        $this->context = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        if ($this->context['origin'] !== 'http://127.0.0.1:8818') { throw new \RuntimeException('只允许隔离端口'); }
        $this->databaseSetUp();
        $this->root = $parent . '/bos-execution'; mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($this->root); $this->databaseTearDown();
        }
    }

    public function testRealWebsiteActivationDownloadNativeInstallAndReport(): void
    {
        $identityPath = $this->context['private_directory'];
        $cipher = new SodiumSensitiveDataCipher(str_repeat('isolated-activation-', 4));
        $state = new DatabaseOfficialPlatformStateStore($cipher, $identityPath);
        $identity = new FileInstallationIdentity($identityPath);
        $client = new HlyqOfficialPlatformClient(new \GuzzleHttp\Client(), $state, $identity, ['origin' => $this->context['origin'], 'allow_local_http' => true], '0.1.0');
        $licenseKey = $this->context['license_key']; $releaseKey = $this->context['release_key'];
        $verifier = new SignedManifestVerifier([$releaseKey['kid'] => $releaseKey['public_key']], 67108864, [$licenseKey['kid'] => $licenseKey['public_key']]);
        $access = new AdminAccessControlService(new DatabaseAdminAccessRepository());
        $licenses = new ManageLicenseService($access, $client, $state, $identity, $verifier, new CapabilityCatalog(), $cipher, new SystemLicenseClock(), new LicenseEntitlementPolicy());
        $actor = new AdminAccount(1, 'isolated', '隔离联调', 'hash', 1, null, true);
        $client->activate($this->context['activation_code']);
        self::assertSame('active', $licenses->refresh($actor)['status']);
        self::assertArrayNotHasKey('activation_code', $state->read());
        $private = $this->root . '/private'; $lock = new FileInstallationLock($private); $jobs = new DatabaseInstallationJobRepository();
        $updates = new ManageSoftwareUpdatesService($access, $client, $state, $identity, $verifier, $licenses, $jobs, $lock, '0.1.0');
        $checked = $updates->check($actor); self::assertSame('0.1.1', $checked['update']['target_version']);
        $id = \app\modules\official_platform\domain\InstanceUuid::create();
        $updates->start($actor, $id, $checked['update']['sha256'], ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机'], '0.1.1');
        mkdir($this->root . '/app', 0755); mkdir($this->root . '/config', 0755);
        file_put_contents($this->root . '/.env', 'protected-isolated-settings');
        file_put_contents($this->root . '/app/functions.php', '<?php // original');
        file_put_contents($this->root . '/config/version.php', "<?php return ['current' => '0.1.0'];");
        file_put_contents($this->root . '/webman', "<?php if (\$argv[1] !== 'system:migrate' || \$argv[2] !== '--force') exit(1); file_put_contents(__DIR__.'/migration-confirmed', '1');");
        file_put_contents($this->root . '/start.php', "<?php if (\$argv[1] !== 'restart' || \$argv[2] !== '-d') exit(1); file_put_contents(__DIR__.'/restart-confirmed', '1');");
        $runner = new RunInstallationJobService($jobs, $lock, $client, $identity, $verifier, $licenses, $this->installer('0.1.0'), $private, '0.1.0');
        $runner->tick();
        self::assertSame('awaiting_restart', $jobs->find($id)['status']);
        self::assertSame('protected-isolated-settings', file_get_contents($this->root . '/.env'));
        self::assertSame('0.1.1', (require $this->root . '/config/version.php')['current']);
        self::assertFileExists($private . '/jobs/' . $id . '/database.sql');
        self::assertFileExists($private . '/jobs/' . $id . '/previous/app/functions.php');
        self::assertFileExists($this->root . '/migration-confirmed'); self::assertFileExists($this->root . '/restart-confirmed');
        $newWorker = new RunInstallationJobService($jobs, $lock, $client, $identity, $verifier, $licenses, $this->installer('0.1.1'), $private, '0.1.1');
        $newWorker->tick();
        self::assertSame('succeeded', $jobs->find($id)['status']); self::assertSame('reported', $jobs->find($id)['report_status']);
        self::assertFileDoesNotExist($private . '/maintenance.json');
        $newWorker->tick(); self::assertSame('succeeded', $jobs->find($id)['status']);
    }

    private function installer(string $version): LocalReleaseInstaller
    {
        $runner = $this->createStub(CoreMigrationRunnerInterface::class); $runner->method('status')->willReturn(new MigrationStatus([], [], [], 0));
        $repository = $this->createStub(SystemUpdateRepositoryInterface::class); $repository->method('currentVersion')->willReturn('0.1.1');
        return new LocalReleaseInstaller(new VerifiedZipPackage(), new DatabaseBackup(new SystemUpdatePdoFactory()),
            new SystemMigrationService($runner, $repository, $this->createStub(SystemMigrationLockInterface::class)), $this->createStub(PluginInstallationRepositoryInterface::class),
            $this->root, $this->root . '/private', $version, MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig()));
    }
}
