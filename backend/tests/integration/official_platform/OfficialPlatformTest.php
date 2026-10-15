<?php

declare(strict_types=1);

namespace tests\integration\official_platform;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\application_center\application\ManageApplicationCenterService;
use app\modules\licensing\application\ManageLicenseService;
use app\modules\official_platform\contract\OfficialPlatformClientInterface;
use app\modules\official_platform\domain\{ClientRequestProof, SignedManifestVerifier};
use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\official_platform\infrastructure\{DatabaseOfficialPlatformStateStore, FileInstallationIdentity, HlyqOfficialPlatformClient};
use app\modules\plugin\application\PluginLifecycleService;
use app\modules\plugin\contract\{PluginPackageRegistryInterface, PluginUsageInspectorInterface};
use app\modules\plugin\infrastructure\DatabasePluginInstallationRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\modules\software_maintenance\application\{ManageSoftwareUpdatesService, RunInstallationJobService};
use app\modules\software_maintenance\contract\ReleaseInstallerInterface;
use app\modules\software_maintenance\infrastructure\{DatabaseInstallationJobRepository, FileInstallationLock, VerifiedZipPackage};
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
use ZipArchive;

/** 独立数据库、Ed25519真实签名及HTTP模拟，绝不写生产官网或覆盖项目代码。 */
final class OfficialPlatformTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as databaseSetUp; tearDown as databaseTearDown; }

    private string $private;
    private DatabaseOfficialPlatformStateStore $store;
    private FileInstallationIdentity $identity;
    private string $pair;
    private const CONNECTION = '1cd51ed1-4a9e-4bfa-817f-5a6adfb1c101';

    protected function setUp(): void
    {
        $this->databaseSetUp();
        $this->private = realpath(sys_get_temp_dir()) . '/howstack-official-' . bin2hex(random_bytes(8));
        $this->store = new DatabaseOfficialPlatformStateStore(new SodiumSensitiveDataCipher(str_repeat('test-only-app-key', 4)), $this->private);
        $this->identity = new FileInstallationIdentity($this->private);
        $this->pair = sodium_crypto_sign_keypair();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->private)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->private, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->private);
        }
        $this->databaseTearDown();
    }

    public function testPrivateStateEncryptedAndInstallationIdentityStable(): void
    {
        $this->store->write(['access_token' => 'sensitive-test-token']);
        self::assertStringNotContainsString('sensitive-test-token', Db::table('official_platform_state')->value('encrypted_state'));
        self::assertSame('sensitive-test-token', $this->store->read()['access_token']);
        $next = new FileInstallationIdentity($this->private);
        self::assertSame($this->identity->id(), $next->id());
        self::assertSame(0600, fileperms($this->private . '/identity.json') & 0777);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($next->sign('proof')), 'proof', base64_decode($this->identity->publicKey())));
    }

    public function testMalformedIdentityFailsWithoutRegeneration(): void
    {
        $id = $this->identity->id();
        file_put_contents($this->private . '/identity.json', 'malformed');
        try { $this->identity->id(); self::fail('损坏身份不能自动重新生成'); }
        catch (OfficialPlatformException $error) { self::assertSame('INVALID_IDENTITY', $error->reason); }
        self::assertNotEmpty($id);
        self::assertSame('malformed', file_get_contents($this->private . '/identity.json'));
    }

    public function testActivationSignatureDoesNotPersistCodeOrTokens(): void
    {
        $history = []; $code = 'HS-' . implode('-', array_fill(0, 6, 'AAAAAAAA'));
        $client = $this->client([$this->response(['connection' => $this->connection()])], $history);
        $client->activate($code);
        $request = $history[0]['request'];
        self::assertFalse($request->hasHeader('Authorization'));
        $bytes = ClientRequestProof::canonical('howstack-bos', $this->identity->id(), 'POST', '/api/client/v1/licenses/activate', (string)$request->getBody(),
            $request->getHeaderLine('X-Howstack-Timestamp'), $request->getHeaderLine('X-Howstack-Nonce'));
        self::assertFalse(str_ends_with($bytes, "\n"));
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($request->getHeaderLine('X-Howstack-Signature')), $bytes, base64_decode($this->identity->publicKey())));
        self::assertStringNotContainsString($code, json_encode($this->store->read()));
        foreach (['credentials', 'pairing', 'activation_code'] as $field) { self::assertArrayNotHasKey($field, $this->store->read()); }
        self::assertCount(1, $history);
    }

    /**
     * 本地前后端分别运行时，API 和签名仍使用授权服务器，确认与购买链接使用官网前端。
     * @return void
     */
    public function testLocalApiAndPortalOriginsRemainSeparate(): void
    {
        $history = [];
        $client = $this->client([$this->response(['connection' => $this->connection()])], $history, ['portal_origin' => 'https://portal.example']);
        $client->activate('HS-' . implode('-', array_fill(0, 6, 'AAAAAAAA')));
        self::assertSame('official.example', $history[0]['request']->getUri()->getHost());
        self::assertSame('https://portal.example/applications', $client->storeUrl());
    }

    /**
     * 本地联调开关不能放行外部 HTTP 页面源站，错误配置不得创建确认申请。
     * @return void
     */
    public function testExternalHttpPortalRejectedBeforeRequest(): void
    {
        $history = [];
        $client = $this->client([], $history, ['portal_origin' => 'http://untrusted.example', 'allow_local_http' => true]);
        try { $client->storeUrl(); self::fail('非本机HTTP应拒绝'); }
        catch (OfficialPlatformException $e) { self::assertSame('INVALID_CONFIGURATION', $e->reason); }
        self::assertCount(0, $history);
    }

    public function testRequestFailureDoesNotCreateTokensOrBlindlyRetry(): void
    {
        $history = [];
        $client = $this->connectedClient([new \RuntimeException('fixture transport')], $history);
        try { $client->owned(); self::fail('应报告网络失败'); }
        catch (OfficialPlatformException $e) { self::assertSame('TRANSPORT_ERROR', $e->reason); }
        self::assertCount(3, $history);
        self::assertArrayNotHasKey('credentials', $this->store->read());
    }

    public function testPartialNetworkReadsStillProduceCompleteProtocolResponse(): void
    {
        $input = ['connection' => $this->connection(), 'ignored_padding' => str_repeat('a', 20000)];
        $body = \GuzzleHttp\Psr7\Utils::streamFor(json_encode(['code' => 'OK', 'data' => $input]));
        $chunks = \GuzzleHttp\Psr7\FnStream::decorate($body, ['read' => static fn (int $length): string => $body->read(min($length, 17))]);
        $history = []; $client = $this->client([new Response(200, [], $chunks)], $history);
        self::assertSame('community', $client->activate('HS-' . implode('-', array_fill(0, 6, 'AAAAAAAA')))['edition_code']);
        self::assertCount(1, $history);
    }

    public function testSignedLicenseExpiryOfflineAndFeatureGate(): void
    {
        $certificate = $this->certificate();
        $this->store->write(['license_certificate' => $this->signed($certificate), 'certificate_connection_id' => self::CONNECTION]);
        $license = $this->license();
        self::assertSame('professional', $license->releaseEdition());
        $license->require('fixture.advanced', \app\shared\entitlement\EntitlementOperation::StartNew);
        $certificate['status'] = 'expired'; $certificate['grants'][0]['use_until'] = time() - 10; $certificate['grants'][0]['updates_until'] = time() - 10;
        $this->store->write(['license_certificate' => $this->signed($certificate), 'certificate_connection_id' => self::CONNECTION]);
        self::assertSame('expired', $license->current()['status']);
        self::assertSame('professional', $license->current()['edition']);
        self::assertSame('professional', $license->releaseEdition());
        $this->expectException(BusinessException::class);
        $license->require('fixture.advanced', \app\shared\entitlement\EntitlementOperation::StartNew);
    }

    /**
     * 官网自定义版本可以完成连接，连接成功不能代替商业证书授权。
     * @return void
     */
    public function testCustomEditionConnectionDoesNotGrantCommercialFeatures(): void
    {
        $connection = array_replace($this->connection(), ['connection_mode' => 'licensed', 'edition_code' => 'premium', 'edition_name' => '旗舰版', 'license_no' => 'TEST-LICENSE', 'license_valid' => true]);
        $history = []; $client = $this->client([$this->response(['connection' => $connection])], $history);
        $client->activate('HS-' . implode('-', array_fill(0, 6, 'AAAAAAAA')));
        self::assertSame('premium', $client->localStatus()['connection']['edition_code']);
        self::assertSame('旗舰版', $client->localStatus()['connection']['edition_name']);
        self::assertSame([], $this->license()->current()['entitlements']);
        $this->expectException(BusinessException::class);
        $this->license()->require('fixture.enterprise', \app\shared\entitlement\EntitlementOperation::StartNew);
    }

    /**
     * 自定义商业版本不限制已签名的细粒度权益，也不隐式授予其他能力。
     * @return void
     */
    public function testCustomEditionCertificateUsesExplicitSignedGrants(): void
    {
        $certificate = array_replace($this->certificate(), [
            'edition' => 'premium',
            'edition_name' => '旗舰版',
        ]);
        $this->store->write([
            'license_certificate' => $this->signed($certificate),
            'certificate_connection_id' => self::CONNECTION,
        ]);

        $license = $this->license();
        self::assertSame('active', $license->current()['status']);
        self::assertSame('premium', $license->releaseEdition());
        self::assertSame('旗舰版', $license->current()['edition_name']);
        self::assertCount(1, $license->current()['entitlements']);

        $license->require('fixture.advanced', \app\shared\entitlement\EntitlementOperation::StartNew);
    }

    public function testOfflineExpiryKeepsPurchasedEditionAndStopsAdvancedOperations(): void
    {
        $certificate = $this->certificate(); $certificate['issued_at'] = time() - 100; $certificate['offline_until'] = time() - 1;
        $this->store->write(['license_certificate' => $this->signed($certificate), 'certificate_connection_id' => self::CONNECTION]);
        self::assertSame('offline_expired', $this->license()->current()['status']);
        self::assertSame('professional', $this->license()->current()['edition']);
    }

    public function testAutomaticLicenseRefreshRetainsCertificateAndBacksOffAfterNetworkFailure(): void
    {
        $connection = array_replace($this->connection(), [
            'connection_mode' => 'licensed', 'edition_code' => 'professional',
            'license_no' => 'TEST-LICENSE', 'license_valid' => true,
        ]);
        $history = [];
        $client = $this->connectedClient([
            $this->response($connection), $this->response($this->signed($this->certificate())),
            new \RuntimeException('private transport diagnostic'),
        ], $history);
        $state = $this->store->read();
        $state['connection'] = $connection;
        $this->store->write($state);
        $license = new ManageLicenseService($this->access(), $client, $this->store, $this->identity,
            $this->verifier(), ...$this->licenseDependencies());
        $license->refreshWhenDue();
        self::assertSame('professional', $license->releaseEdition());
        $license->refreshWhenDue();
        self::assertCount(4, $history);

        $state = $this->store->read();
        $state['license_refresh_attempt_at'] = 0;
        $state['license_checked_at'] = '2020-01-01 00:00:00';
        $this->store->write($state);
        $license->refreshWhenDue();
        self::assertSame('TRANSPORT_ERROR', $this->store->read()['license_error']);
        self::assertSame('professional', $license->releaseEdition());
        $license->refreshWhenDue();
        self::assertCount(5, $history);
    }

    public function testIdentityDirectoryRejectsPublicPathsAndAncestorSymlinks(): void
    {
        $this->identity->id();
        $alias = $this->private . '/alias';
        symlink($this->private, $alias);
        try {
            (new FileInstallationIdentity($alias . '/credentials'))->id();
            self::fail('私有目录祖先链接不能改变身份存储位置');
        } catch (OfficialPlatformException $error) {
            self::assertSame('INVALID_CONFIGURATION', $error->reason);
        }
        $this->expectException(OfficialPlatformException::class);
        (new FileInstallationIdentity(base_path('public/official-platform')))->id();
    }

    public function testUnknownSigningKeyAndDifferentInstanceAreRejected(): void
    {
        $envelope = $this->signed($this->certificate()); $envelope['kid'] = 'attacker';
        try { $this->verifier()->certificate($envelope, $this->identity->id(), self::CONNECTION); self::fail('不能自动信任新密钥'); }
        catch (OfficialPlatformException $error) { self::assertSame('UNTRUSTED_SIGNATURE', $error->reason); }
        $this->expectException(OfficialPlatformException::class);
        $this->verifier()->certificate($this->signed($this->certificate()), 'other-instance', self::CONNECTION);
    }

    public function testCommunityIsDefaultAndRoleCannotCheckUpdate(): void
    {
        self::assertSame('community', $this->license()->current()['status']);
        self::assertSame('社区版', $this->license()->current()['edition_name']);
        $client = $this->createMock(OfficialPlatformClientInterface::class);
        $client->expects(self::never())->method('synchronize');
        $roles = $this->createStub(AdminAccessRepositoryInterface::class);
        $roles->method('grantsForAdmin')->willReturn([['permissions' => ['admin.updates.view']]]);
        $service = $this->updates($client, new AdminAccessControlService($roles));
        $viewer = new AdminAccount(2, 'viewer', '仅查看', 'hash', 1, null);
        self::assertSame('0.1.0', $service->status($viewer)['current_version']);
        $this->expectException(BusinessException::class);
        $service->check($viewer);
    }

    /** 缺少授权管理权限不能先改变官网绑定再在本机报错。 */
    public function testConnectionPermissionAloneCannotActivateOrDisconnect(): void
    {
        $client = $this->createMock(OfficialPlatformClientInterface::class);
        $client->expects(self::never())->method('activate');
        $client->expects(self::never())->method('disconnect');
        $roles = $this->createStub(AdminAccessRepositoryInterface::class);
        $roles->method('grantsForAdmin')->willReturn([['permissions' => ['admin.platform.connect']]]);
        $service = new \app\modules\official_platform\application\ManageOfficialConnectionService(
            new AdminAccessControlService($roles), $client, new DatabaseInstallationJobRepository(), new FileInstallationLock($this->private),
        );
        $actor = new AdminAccount(2, 'viewer', '仅连接', 'hash', 1, null);
        foreach (['activate', 'disconnect'] as $action) {
            try {
                $action === 'activate' ? $service->activate($actor, 'test') : $service->disconnect($actor);
                self::fail('缺少授权管理权限必须拒绝');
            } catch (BusinessException $error) {
                self::assertSame(403, $error->httpStatus());
            }
        }
    }

    /** 官网社区版名称用于展示；空名称或未经授权的商业连接保持默认。 */
    public function testCommunityDisplayNameComesFromOfficialConnection(): void
    {
        foreach ([
            [true, 'community', 'community', '官网社区体验版', '官网社区体验版'],
            [true, 'community', 'free', '官网免费版', '官网免费版'],
            [true, 'community', 'community', '   ', '社区版'],
            [false, 'community', 'community', '官网社区体验版', '社区版'],
            [true, 'licensed', 'premium', '旗舰版', '社区版'],
        ] as [$connected, $mode, $edition, $name, $expected]) {
            $client = $this->createStub(OfficialPlatformClientInterface::class);
            $client->method('localStatus')->willReturn([
                'connected' => $connected,
                'connection' => ['connection_mode' => $mode, 'edition_code' => $edition, 'edition_name' => $name],
            ]);
            $license = new ManageLicenseService(
                $this->access(), $client, $this->store, $this->identity, $this->verifier(), ...$this->licenseDependencies(),
            );
            $result = $license->status($this->actor());
            self::assertSame($expected, $result['edition_name']);
            self::assertSame('community', $license->releaseEdition());
            self::assertSame([], $result['entitlements']);
        }
    }

    /** 缺失名称仅使用展示默认值，不通过名称推断或改变已验签的权益。 */
    public function testMissingCertificateEditionNameUsesCommercialDisplayFallback(): void
    {
        $this->store->write([
            'license_certificate' => $this->signed($this->certificate()),
            'certificate_connection_id' => self::CONNECTION,
        ]);
        $result = $this->license()->current();
        self::assertSame('商业版', $result['edition_name']);
        self::assertSame('professional', $this->license()->releaseEdition());
        self::assertSame('fixture.advanced', $result['entitlements'][0]['capability']);
    }

    public function testMissingUpdateEndpointCannotBecomeLatest(): void
    {
        $history = [];
        $client = $this->connectedClient([$this->response($this->connection()), new Response(404)], $history);
        try { $this->updates($client)->check($this->actor()); self::fail('接口不存在不能标记已最新'); }
        catch (OfficialPlatformException $error) { self::assertSame('ENDPOINT_NOT_AVAILABLE', $error->reason); }
        self::assertNull($this->updates($client)->status($this->actor())['update']);
    }

    public function testJsonMissingUpdateEndpointReportsIntegrationRequirement(): void
    {
        $history = [];
        $missing = new Response(404, ['Content-Type' => 'application/json'], json_encode([
            'code' => 'NOT_FOUND',
            'message' => '接口不存在',
            'data' => null,
        ]));
        $client = $this->connectedClient([$this->response($this->connection()), $missing], $history);

        try {
            $this->updates($client)->check($this->actor());
            self::fail('JSON 404 不能当作网络失败或检查成功');
        } catch (OfficialPlatformException $error) {
            self::assertSame('ENDPOINT_NOT_AVAILABLE', $error->reason);
            self::assertStringContainsString('官网尚未提供', $error->getMessage());
        }

        self::assertNull($this->updates($client)->status($this->actor())['update']);
        self::assertSame(0, Db::table('software_installation_jobs')->count());
    }

    public function testMissingCommercialLicenseRemainsBusinessFailure(): void
    {
        $history = [];
        $client = $this->connectedClient([new Response(404, [], json_encode([
            'code' => 'NO_COMMERCIAL_LICENSE',
            'data' => null,
        ]))], $history);

        try {
            $client->certificate();
            self::fail('未购买商业授权不能读取证书');
        } catch (OfficialPlatformException $error) {
            self::assertSame('NO_COMMERCIAL_LICENSE', $error->reason);
        }
    }

    public function testStoreLinkUsesPortalWithoutConnectionOrRemoteRequests(): void
    {
        $history = [];
        $client = $this->client([], $history, ['portal_origin' => 'https://store.example']);
        self::assertSame(['store_url' => 'https://store.example/applications'], $this->applications($client)->storeLink($this->actor()));
        self::assertCount(0, $history);

        $fallback = $this->client([], $history);
        self::assertSame('https://official.example/applications', $fallback->storeUrl());
        self::assertCount(0, $history);
    }

    public function testOwnedApplicationsJoinOnlyLocalPluginStateAndHideExtraFields(): void
    {
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        $client->method('owned')->willReturn(['items' => [
            ['application_id' => 101, 'code' => 'demo_app', 'name' => '测试插件', 'kind' => 'plugin', 'force_update' => true, 'private_token' => 'must-not-leak'],
            ['application_id' => 102, 'code' => 'demo_app', 'name' => '测试主题', 'kind' => 'theme'],
        ]]);
        $plugins = $this->applicationPlugins();
        $installed = $plugins->transition('demo_app', 'install', 0, 1);
        $service = $this->applications($client, $plugins);
        $items = $service->owned($this->actor())['items'];
        self::assertCount(2, $items);
        self::assertSame('installed', $items[0]['local_state']);
        self::assertTrue($items[0]['force_update']);
        self::assertSame($installed['revision'], $items[0]['local_revision']);
        self::assertSame('not_installed', $items[1]['local_state']);
        self::assertArrayNotHasKey('private_token', $items[0]);

        $service->uninstall($this->actor(), 'demo_app', $installed['revision'], ['client_ip' => '127.0.0.1', 'client_ip_location' => '本地']);
        $items = $service->owned($this->actor())['items'];
        self::assertSame('uninstalled', $items[0]['local_state']);
        self::assertNull($items[0]['local_version']);
        self::assertSame(2, Db::table('business_plugin_events')->where('plugin_id', 'demo_app')->count());
        self::assertSame('127.0.0.1', Db::table('business_plugin_events')->where('plugin_id', 'demo_app')->where('action', 'uninstall')->value('client_ip'));
    }

    public function testApplicationUninstallRequiresOwnPermissionWithoutRemoteAccess(): void
    {
        $client = $this->createMock(OfficialPlatformClientInterface::class);
        $client->expects(self::never())->method('owned');
        $plugins = $this->applicationPlugins();
        $installed = $plugins->transition('demo_app', 'install', 0, 1);
        $roles = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants = ['admin.app-center.view'];
        $roles->method('grantsForAdmin')->willReturnCallback(static function () use (&$grants): array {
            return [['permissions' => $grants]];
        });
        $service = $this->applications($client, $plugins, new AdminAccessControlService($roles));
        $viewer = new AdminAccount(1, 'viewer', '仅查看', 'hash', 1, null);

        try {
            $service->uninstall($viewer, 'demo_app', $installed['revision'], []);
            self::fail('只有查看权限不能卸载');
        } catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        self::assertSame('installed', $plugins->list()[0]['state']);

        $grants = ['admin.app-center.view', 'admin.app-center.uninstall'];
        self::assertSame('uninstalled', $service->uninstall($viewer, 'demo_app', $installed['revision'], [])['state']);
    }

    public function testApplicationUninstallPreservesReferencesAndRejectsStaleRevision(): void
    {
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        $plugins = $this->applicationPlugins(references: 1);
        $installed = $plugins->transition('demo_app', 'install', 0, 1);
        $service = $this->applications($client, $plugins);
        foreach ([0, $installed['revision']] as $revision) {
            try {
                $service->uninstall($this->actor(), 'demo_app', $revision, []);
                self::fail('状态冲突或业务引用必须阻止卸载');
            } catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        }
        self::assertSame('installed', $plugins->list()[0]['state']);
        self::assertSame(1, Db::table('business_plugin_events')->where('plugin_id', 'demo_app')->count());
    }

    public function testApplicationUninstallRejectedDuringActiveInstallation(): void
    {
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        $plugins = $this->applicationPlugins();
        $installed = $plugins->transition('demo_app', 'install', 0, 1);
        (new DatabaseInstallationJobRepository())->create($this->job('a36efc6b-2f1e-4eae-b775-fc77ff876644'));
        try {
            $this->applications($client, $plugins)->uninstall($this->actor(), 'demo_app', $installed['revision'], []);
            self::fail('活跃安装任务必须阻止卸载');
        } catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame('installed', $plugins->list()[0]['state']);
    }

    public function testPreviouslyUninstalledApplicationCanRequestReinstallation(): void
    {
        $manifest = array_replace($this->release(), [
            'type' => 'application-release', 'kind' => 'plugin', 'application_id' => 101,
            'application_code' => 'demo_app', 'version_id' => 501, 'permissions' => [],
            'download_path' => '/api/client/v1/application-versions/501/package?current_version=0.1.0',
        ]);
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        $client->method('synchronize')->willReturn($this->connection());
        $client->method('applicationManifest')->willReturn($this->signed($manifest));
        $plugins = $this->applicationPlugins();
        $installed = $plugins->transition('demo_app', 'install', 0, 1);
        $plugins->transition('demo_app', 'uninstall', $installed['revision'], 1);
        $service = $this->applications($client, $plugins);
        $id = 'a36efc6b-2f1e-4eae-b775-fc77ff876644';
        $service->start($this->actor(), 501, $id, $manifest['sha256'], 'install', []);
        self::assertSame('install', (new DatabaseInstallationJobRepository())->find($id)['action']);
    }

    public function testUpdateJobIdempotencyGlobalConflictAndSafeProjection(): void
    {
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        $client->method('localStatus')->willReturn(['connection' => $this->connection()]);
        $client->method('synchronize')->willReturn($this->connection());
        $client->method('checkUpdate')->willReturn(['available' => true, 'current_version' => '0.1.0', 'manifest' => $this->signed($this->release())]);
        $service = $this->updates($client);
        self::assertTrue($service->check($this->actor())['update']['available']);
        $id = 'a36efc6b-2f1e-4eae-b775-fc77ff876644';
        $service->start($this->actor(), $id, str_repeat('a', 64), ['client_ip' => '127.0.0.1', 'client_ip_location' => '本地'], '0.2.0');
        $service->start($this->actor(), $id, str_repeat('a', 64), ['client_ip' => '127.0.0.1', 'client_ip_location' => '本地'], '0.2.0');
        self::assertSame(1, Db::table('software_installation_jobs')->count());
        self::assertArrayNotHasKey('manifest_json', $service->status($this->actor())['jobs'][0]);
        $this->expectException(BusinessException::class);
        $service->start($this->actor(), 'bb6efc6b-2f1e-4eae-b775-fc77ff876644', str_repeat('a', 64), [], '0.2.0');
    }

    public function testOfficialV1CheckUsesSignedManifestAndFixedVersionQuery(): void
    {
        $history = [];
        $client = $this->connectedClient([
            $this->response($this->connection()),
            $this->response($this->updateResponse()),
        ], $history);
        $service = $this->updates($client);
        self::assertSame('0.2.0', $service->check($this->actor())['update']['target_version']);
        $request = $history[array_key_last($history)]['request'];
        self::assertSame(http_build_query(['architecture' => php_uname('m'), 'current_version' => '0.1.0', 'platform' => strtolower(PHP_OS_FAMILY), 'role' => 'system'], '', '&', PHP_QUERY_RFC3986), $request->getUri()->getQuery());
        self::assertArrayNotHasKey('software_release', $this->store->read());
        self::assertArrayNotHasKey('download_path', $service->notice($this->actor()));
    }

    public function testStructuredChangelogAndForcedUpdateReachNoticeAndSurviveFailure(): void
    {
        $client = $this->updateClient();
        $client->method('checkUpdate')->willReturnOnConsecutiveCalls(
            $this->updateResponse(['force_update' => true]),
            $this->updateResponse(['changelog' => '旧字符串格式']),
        );
        $service = $this->updates($client);
        $update = $service->check($this->actor())['update'];
        self::assertTrue($update['force_update']);
        self::assertSame($this->release()['changelog'], $update['changelog']);
        try {
            $service->check($this->actor());
            self::fail('字符串更新说明不应被接受');
        } catch (OfficialPlatformException $error) {
            self::assertSame('INVALID_RELEASE_MANIFEST', $error->reason);
        }
        $notice = $service->notice($this->actor());
        self::assertTrue($notice['force_update']);
        self::assertSame($update['changelog'], $notice['changelog']);
        self::assertNotNull($notice['last_error']);
        self::assertArrayNotHasKey('download_path', $notice);
    }

    /** @return iterable<string,array{array<string,mixed>}> 错误的官网 v1 发布字段 */
    public static function invalidReleaseContent(): iterable
    {
        $empty = ['added' => [], 'improved' => [], 'fixed' => [], 'notices' => []];
        yield '字符串日志' => [['changelog' => '旧格式']];
        yield '缺分类' => [['changelog' => ['added' => []]]];
        yield '额外分类' => [['changelog' => $empty + ['other' => []]]];
        yield '非列表' => [['changelog' => array_replace($empty, ['added' => ['key' => '新增']])]];
        yield '非字符串条目' => [['changelog' => array_replace($empty, ['added' => [false]])]];
        yield '空白条目' => [['changelog' => array_replace($empty, ['added' => ['   ']])]];
        yield '超过五十项' => [['changelog' => array_replace($empty, ['added' => array_fill(0, 51, '新增')])]];
        yield '超过一千字' => [['changelog' => array_replace($empty, ['added' => [str_repeat('字', 1001)]])]];
        yield '超过32KB' => [['changelog' => array_replace($empty, ['added' => array_fill(0, 12, str_repeat('字', 1000))])]];
        yield '字符串强制标识' => [['force_update' => 'false']];
        yield '整数强制标识' => [['force_update' => 1]];
        yield '空强制标识' => [['force_update' => null]];
    }

    #[DataProvider('invalidReleaseContent')]
    public function testInvalidSignedReleaseContentIsRejected(array $changes): void
    {
        $this->expectException(OfficialPlatformException::class);
        $this->verifier()->release($this->signed(array_replace($this->release(), $changes)),
            'system', $this->identity->id(), self::CONNECTION, '0.1.0');
    }

    public function testOldStringSummaryIsDiscardedAndRefreshedOnSchedule(): void
    {
        $client = $this->updateClient(true);
        $client->expects(self::exactly(2))->method('checkUpdate')->willReturn($this->updateResponse());
        $service = $this->updates($client);
        $service->check($this->actor());
        $this->ageUpdateAttempt(1000);
        $this->store->synchronized(function (): void {
            $state = $this->store->read();
            $state['software_update']['changelog'] = '旧字符串摘要';
            $this->store->write($state);
        });
        self::assertNull($service->status($this->actor())['update']);
        self::assertFalse($service->notice($this->actor())['force_update']);
        $service->refreshWhenDue();
        self::assertSame($this->release()['changelog'], $service->notice($this->actor())['changelog']);
    }

    public function testScheduledCheckRunsAfterConnectionAndRespectsTwelveHourInterval(): void
    {
        $client = $this->updateClient(true);
        $client->expects(self::exactly(2))->method('checkUpdate')->willReturn($this->updateResponse());
        $service = $this->updates($client);
        $service->refreshWhenDue();
        $service->refreshWhenDue();
        self::assertSame('0.2.0', $service->notice($this->actor())['latest_version']);
        $this->ageUpdateAttempt(43201, true);
        $service->refreshWhenDue();
        self::assertNull($service->status($this->actor())['last_error']);
    }

    public function testDisconnectedInstanceDoesNotCheckAndNoticeReadsOnlyCache(): void
    {
        $client = $this->createMock(OfficialPlatformClientInterface::class);
        $client->method('localStatus')->willReturn(['configured' => false, 'connected' => false, 'connection' => null]);
        $client->expects(self::never())->method('checkUpdate');
        $client->expects(self::never())->method('synchronize');
        $service = $this->updates($client);
        $service->refreshWhenDue();
        self::assertFalse($service->notice($this->actor())['available']);
        self::assertNull($service->notice($this->actor())['checked_at']);
    }

    public function testFailedCheckPreservesVerifiedSummaryAndThrottlesRetries(): void
    {
        $client = $this->updateClient();
        $calls = 0;
        $client->method('checkUpdate')->willReturnCallback(function () use (&$calls): array {
            if (++$calls === 1) {
                return $this->updateResponse();
            }
            throw new OfficialPlatformException('TRANSPORT_ERROR');
        });
        $service = $this->updates($client);
        $success = $service->check($this->actor())['update'];
        try {
            $service->check($this->actor());
            self::fail('检查失败须返回失败');
        } catch (OfficialPlatformException $error) {
            self::assertSame('TRANSPORT_ERROR', $error->reason);
        }
        $notice = $service->notice($this->actor());
        self::assertTrue($notice['available']);
        self::assertSame($success['checked_at'], $notice['checked_at']);
        self::assertNotNull($notice['last_error']);
        self::assertNotNull($notice['last_attempt_at']);
        $service->refreshWhenDue();
        self::assertSame(2, $calls);
        self::assertArrayNotHasKey('manifest', $notice);
        self::assertArrayNotHasKey('signature', $notice);
    }

    public function testSchedulerRetriesFailureAfterFifteenMinutes(): void
    {
        $client = $this->updateClient(true);
        $client->expects(self::exactly(2))->method('checkUpdate')->willThrowException(new OfficialPlatformException('TRANSPORT_ERROR'));
        $service = $this->updates($client);
        $service->refreshWhenDue();
        $service->refreshWhenDue();
        $this->ageUpdateAttempt(901);
        $service->refreshWhenDue();
        self::assertNull($service->status($this->actor())['update']);
    }

    public function testUpdateAfterCachedManifestExpiryFetchesFreshSignedManifest(): void
    {
        $client = $this->updateClient(true);
        $client->expects(self::exactly(2))->method('checkUpdate')->willReturn($this->updateResponse());
        $service = $this->updates($client);
        $service->check($this->actor());
        $this->store->synchronized(function (): void {
            $state = $this->store->read();
            $state['software_update']['expires_at'] = time() - 3600;
            $state['software_release'] = $this->signed(array_replace($this->release(), ['issued_at' => time() - 4000, 'expires_at' => time() - 3400]));
            $this->store->write($state);
        });
        self::assertTrue($service->notice($this->actor())['available']);
        $id = 'a36efc6b-2f1e-4eae-b775-fc77ff876644';
        $service->start($this->actor(), $id, str_repeat('a', 64), [], '0.2.0');
        $job = (new DatabaseInstallationJobRepository())->find($id);
        self::assertGreaterThan(time(), json_decode($job['manifest_json'], true)['expires_at']);
        self::assertArrayNotHasKey('software_release', $this->store->read());
    }

    public function testChangedPackageRequiresNewConfirmationAndCannotCreateJob(): void
    {
        $client = $this->updateClient();
        $client->method('checkUpdate')->willReturnOnConsecutiveCalls(
            $this->updateResponse(),
            $this->updateResponse(['version' => '0.3.0', 'sha256' => str_repeat('b', 64)]),
        );
        $service = $this->updates($client);
        $service->check($this->actor());
        try {
            $service->start($this->actor(), 'a36efc6b-2f1e-4eae-b775-fc77ff876644', str_repeat('a', 64), [], '0.2.0');
            self::fail('不能静默切换目标版本');
        } catch (OfficialPlatformException $error) {
            self::assertSame('RELEASE_CONTEXT_MISMATCH', $error->reason);
        }
        self::assertSame(0, Db::table('software_installation_jobs')->count());
        self::assertSame('0.3.0', $service->notice($this->actor())['latest_version']);
    }

    public function testInvalidSignatureCannotReplacePreviouslyVerifiedSummary(): void
    {
        $client = $this->updateClient();
        $invalid = $this->updateResponse(['version' => '0.3.0']);
        $invalid['manifest']['signature'] = base64_encode(str_repeat('x', 64));
        $client->method('checkUpdate')->willReturnOnConsecutiveCalls($this->updateResponse(), $invalid);
        $service = $this->updates($client);
        $service->check($this->actor());
        try {
            $service->check($this->actor());
            self::fail('错误签名不能覆盖缓存');
        } catch (OfficialPlatformException $error) {
            self::assertSame('UNTRUSTED_SIGNATURE', $error->reason);
        }
        self::assertSame('0.2.0', $service->notice($this->actor())['latest_version']);
    }

    public function testNoticeCannotBeReadWithoutUpdateViewPermission(): void
    {
        $client = $this->createMock(OfficialPlatformClientInterface::class);
        $client->expects(self::never())->method('localStatus');
        $roles = $this->createStub(AdminAccessRepositoryInterface::class);
        $roles->method('grantsForAdmin')->willReturn([]);
        $this->expectException(BusinessException::class);
        $this->updates($client, new AdminAccessControlService($roles))->notice(new AdminAccount(2, 'viewer', '无权限', 'hash', 1, null));
    }

    public function testCachedSummaryDoesNotFollowAChangedConnection(): void
    {
        $client = $this->updateClient();
        $client->method('checkUpdate')->willReturn($this->updateResponse());
        $this->updates($client)->check($this->actor());
        $other = $this->createStub(OfficialPlatformClientInterface::class);
        $other->method('localStatus')->willReturn(['connection' => ['connection_id' => 'b36efc6b-2f1e-4eae-b775-fc77ff876644']]);
        self::assertFalse($this->updates($other)->notice($this->actor())['available']);
        $upgraded = new ManageSoftwareUpdatesService($this->access(), $client, $this->store, $this->identity, $this->verifier(),
            $this->license(), new DatabaseInstallationJobRepository(), new FileInstallationLock($this->private), '0.2.0');
        self::assertFalse($upgraded->notice($this->actor())['available']);
    }

    /** @return OfficialPlatformClientInterface 已连接测试客户端 */
    private function updateClient(bool $expectCalls = false): OfficialPlatformClientInterface
    {
        $client = $expectCalls ? $this->createMock(OfficialPlatformClientInterface::class) : $this->createStub(OfficialPlatformClientInterface::class);
        $client->method('localStatus')->willReturn(['configured' => true, 'connected' => true, 'connection' => $this->connection()]);
        $client->method('synchronize')->willReturn($this->connection());
        return $client;
    }

    /** @return array<string,mixed> 使用正式 v1 字段的已签名测试响应 */
    private function updateResponse(array $changes = []): array
    {
        return ['available' => true, 'current_version' => '0.1.0', 'manifest' => $this->signed(array_replace($this->release(), $changes))];
    }

    private function ageUpdateAttempt(int $seconds, bool $success = false): void
    {
        $this->store->synchronized(function () use ($seconds, $success): void {
            $state = $this->store->read();
            $state['software_update_attempt']['checked_at'] = date('Y-m-d H:i:s', time() - $seconds);
            if ($success) {
                $state['software_update']['checked_at'] = date('Y-m-d H:i:s', time() - $seconds);
            }
            $this->store->write($state);
        });
    }

    public function testUnknownRemoteFailureCodeDoesNotLeakIntoResponse(): void
    {
        $error = new OfficialPlatformException('private secret: injected');
        self::assertSame('INVALID_RESPONSE', $error->reason);
        self::assertStringNotContainsString('private secret', $error->getMessage());
    }

    public function testDownloadRedirectDropsOfficialCredentialsAndChecksHash(): void
    {
        $history = [];
        $client = $this->connectedClient([new Response(302, ['Location' => 'https://packages.example/core.zip']), new Response(200, [], 'fixture')], $history);
        $path = $this->private . '/package.zip';
        $client->download(['role' => 'system', 'first_published_at' => time() - 60, 'required_capability' => '', 'download_path' => '/api/client/v1/software-packages/updates/1?' . http_build_query(['architecture' => php_uname('m'), 'current_version' => '0.1.0', 'platform' => strtolower(PHP_OS_FAMILY), 'role' => 'system'], '', '&', PHP_QUERY_RFC3986), 'bytes' => 7, 'sha256' => hash('sha256', 'fixture')], $path);
        self::assertSame('fixture', file_get_contents($path));
        self::assertNotEmpty($history[2]['request']->getHeaderLine('X-Howstack-Signature'));
        self::assertFalse($history[2]['request']->hasHeader('Authorization'));
        self::assertFalse($history[3]['request']->hasHeader('Authorization'));
        self::assertFalse($history[3]['request']->hasHeader('X-Howstack-Signature'));
    }

    public function testInvalidDownloadHashDoesNotLeaveACompletedPackage(): void
    {
        $history = [];
        $client = $this->connectedClient([new Response(200, [], 'fixture')], $history);
        try { $client->download(['download_path' => '/api/client/v1/software-packages/updates/1', 'bytes' => 7, 'sha256' => str_repeat('a', 64)], $this->private . '/package.zip'); self::fail('摘要不符必须拒绝'); }
        catch (OfficialPlatformException $error) { self::assertSame('PACKAGE_INTEGRITY_ERROR', $error->reason); }
        self::assertFileDoesNotExist($this->private . '/package.zip');
        self::assertFileDoesNotExist($this->private . '/package.zip.part');
    }

    public function testZipTraversalAndSymlinksCannotOverwriteFiles(): void
    {
        foreach (['../escaped.php', 'link.php'] as $index => $name) {
            $path = $this->private . '/unsafe' . $index . '.zip';
            $this->identity->id();
            $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString($name, 'untrusted');
            if ($index === 1) $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
            $zip->close();
            try { (new VerifiedZipPackage())->extract($path, $this->private . '/stage' . $index); self::fail('不安全路径必须拒绝'); }
            catch (OfficialPlatformException $error) { self::assertSame('UNSAFE_PACKAGE_PATH', $error->reason); }
        }
        self::assertFileDoesNotExist(dirname($this->private) . '/escaped.php');
    }

    public function testCrashAfterFileReplacementRequiresRecoveryWithoutReexecution(): void
    {
        $repo = new DatabaseInstallationJobRepository();
        $id = 'a36efc6b-2f1e-4eae-b775-fc77ff876644';
        $repo->create($this->job($id));
        $repo->update($id, ['status' => 'running', 'phase' => 'migrating', 'lease_until' => '2020-01-01 00:00:00']);
        $installer = $this->createMock(ReleaseInstallerInterface::class);
        $installer->expects(self::never())->method('install');
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        $runner = new RunInstallationJobService($repo, new FileInstallationLock($this->private), $client, $this->identity,
            $this->verifier(), $this->license(), $installer, $this->private, '0.1.0');
        $runner->tick();
        self::assertSame('recovery_required', $repo->find($id)['status']);
        $runner->tick();
        self::assertSame('recovery_required', $repo->find($id)['status']);
    }

    public function testRebootOnlyCompletesAfterInstallerVerifiesActualState(): void
    {
        $repo = new DatabaseInstallationJobRepository(); $id = 'a36efc6b-2f1e-4eae-b775-fc77ff876644';
        $repo->create($this->job($id)); $repo->update($id, ['status' => 'awaiting_restart']);
        $installer = $this->createStub(ReleaseInstallerInterface::class);
        $installer->method('confirm')->willReturnOnConsecutiveCalls(false, true);
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        $runner = new RunInstallationJobService($repo, new FileInstallationLock($this->private), $client, $this->identity,
            $this->verifier(), $this->license(), $installer, $this->private, '0.2.0');
        $runner->tick(); self::assertSame('awaiting_restart', $repo->find($id)['status']);
        $runner->tick(); self::assertSame('succeeded', $repo->find($id)['status']);
    }

    public function testPendingReportOutsideRecentPageIsRetriedWithoutReinstalling(): void
    {
        $repo = new DatabaseInstallationJobRepository();
        $oldId = \app\modules\official_platform\domain\InstanceUuid::create();
        $template = $this->job($oldId) + ['status' => 'failed'];
        $template['kind'] = 'application';
        $template['report_status'] = 'pending';
        $repo->create($template);
        for ($index = 0; $index < 51; $index++) {
            $repo->create(array_replace($template, [
                'request_id' => \app\modules\official_platform\domain\InstanceUuid::create(),
                'report_status' => 'none',
            ]));
        }
        self::assertNotContains($oldId, array_column($repo->recent(), 'request_id'));
        $client = $this->createMock(OfficialPlatformClientInterface::class);
        $client->expects(self::once())->method('reportOperation')->with($oldId, 'failed', '');
        $installer = $this->createMock(ReleaseInstallerInterface::class);
        $installer->expects(self::never())->method('install');
        $runner = new RunInstallationJobService($repo, new FileInstallationLock($this->private), $client,
            $this->identity, $this->verifier(), $this->license(), $installer, $this->private, '0.1.0');
        $runner->tick();
        self::assertSame('reported', $repo->find($oldId)['report_status']);
    }

    public function testOfficialTablesHaveChineseCommentsOnMySql(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('字段注释使用独立MySQL测试库验证');
        }
        $pdo = Db::connection()->getPdo();
        foreach (['orm_official_platform_state', 'orm_software_installation_jobs'] as $table) {
            $query = $pdo->prepare('SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $query->execute([$table]);
            foreach ($query->fetchAll(\PDO::FETCH_COLUMN) as $comment) {
                self::assertMatchesRegularExpression('/\p{Han}/u', $comment);
            }
        }
    }

    private function job(string $id): array
    {
        return ['request_id' => $id, 'kind' => 'system', 'action' => 'update', 'target_code' => 'howstack-bos',
            'version_id' => null, 'from_version' => '0.1.0', 'target_version' => '0.2.0', 'manifest_json' => json_encode($this->release())];
    }

    private function applicationPlugins(int $references = 0): PluginLifecycleService
    {
        $packages = $this->createStub(PluginPackageRegistryInterface::class);
        $packages->method('packages')->willReturn(['demo_app' => [
            'id' => 'demo_app', 'name' => '测试插件', 'category' => 'provider', 'version' => '0.2.0',
            'package_hash' => str_repeat('a', 64), 'configuration_fields' => [],
        ]]);
        $packages->method('failures')->willReturn([]);
        $usage = $this->createStub(PluginUsageInspectorInterface::class);
        $usage->method('inspect')->willReturn(['references' => $references, 'active_references' => 0]);

        return new PluginLifecycleService($packages, new DatabasePluginInstallationRepository(new DatabaseTransactionManager(), [$usage]));
    }

    private function applications(OfficialPlatformClientInterface $client, ?PluginLifecycleService $plugins = null, ?AdminAccessControlService $access = null): ManageApplicationCenterService
    {
        return new ManageApplicationCenterService($access ?? $this->access(), $client, $this->identity,
            $this->verifier(), $plugins ?? $this->applicationPlugins(), new DatabaseInstallationJobRepository(),
            new FileInstallationLock($this->private), '0.1.0');
    }

    private function actor(): AdminAccount { return new AdminAccount(1, 'fixture-admin', '测试', 'hash', 1, null, true); }
    private function access(): AdminAccessControlService
    {
        $repo = $this->createStub(AdminAccessRepositoryInterface::class); $repo->method('grantsForAdmin')->willReturn([]);
        return new AdminAccessControlService($repo);
    }
    private function verifier(): SignedManifestVerifier { return new SignedManifestVerifier(['test-key' => base64_encode(sodium_crypto_sign_publickey($this->pair))], 536870912, ['test-key' => base64_encode(sodium_crypto_sign_publickey($this->pair))]); }
    private function license(): ManageLicenseService
    {
        $client = $this->createStub(OfficialPlatformClientInterface::class);
        return new ManageLicenseService($this->access(), $client, $this->store, $this->identity, $this->verifier(), ...$this->licenseDependencies());
    }
    private function updates(OfficialPlatformClientInterface $client, ?AdminAccessControlService $access = null): ManageSoftwareUpdatesService
    {
        return new ManageSoftwareUpdatesService($access ?? $this->access(), $client, $this->store, $this->identity, $this->verifier(),
            $this->license(), new DatabaseInstallationJobRepository(), new FileInstallationLock($this->private), '0.1.0');
    }
    /** @return array 仅测试环境注册的能力，不加入生产目录 */
    private function licenseDependencies(): array
    {
        return [
            new \app\modules\licensing\domain\CapabilityCatalog([
                new \app\modules\licensing\domain\CapabilityDefinition('fixture.advanced', '测试能力', ['start_new' => 'admin.license.view']),
            ]),
            new SodiumSensitiveDataCipher(str_repeat('test-only-app-key', 4)),
            new \app\modules\licensing\infrastructure\SystemLicenseClock(),
            new \app\modules\licensing\domain\LicenseEntitlementPolicy(),
        ];
    }
    private function certificate(): array
    {
        return ['schema_version' => 1, 'type' => 'software-license', 'software_code' => 'howstack-bos',
            'installation_id' => $this->identity->id(), 'connection_id' => self::CONNECTION, 'license_no' => 'TEST-LICENSE',
            'edition' => 'professional', 'status' => 'active', 'revision' => 1, 'issued_at' => time() - 1,
            'offline_policy' => 'online_lease', 'offline_until' => time() + 3600,
            'grants' => [['capability' => 'fixture.advanced', 'use_until' => time() + 86400, 'updates_until' => time() + 86400, 'limits' => []]]];
    }
    private function release(): array
    {
        return ['schema_version' => 1, 'type' => 'software-release', 'software_code' => 'howstack-bos', 'installation_id' => $this->identity->id(),
            'connection_id' => self::CONNECTION, 'edition' => 'community', 'from_version' => '0.1.0', 'version' => '0.2.0',
            'version_id' => 2, 'changelog' => ['added' => [], 'improved' => [], 'fixed' => ['修复更新问题'], 'notices' => []], 'force_update' => false,
            'channel' => 'stable', 'platform' => 'universal', 'architecture' => 'universal', 'package_type' => 'incremental',
            'package_id' => 1, 'bytes' => 7, 'sha256' => str_repeat('a', 64), 'role' => 'system', 'first_published_at' => time() - 60, 'required_capability' => '', 'download_path' => '/api/client/v1/software-packages/updates/1?' . http_build_query(['architecture' => php_uname('m'), 'current_version' => '0.1.0', 'platform' => strtolower(PHP_OS_FAMILY), 'role' => 'system'], '', '&', PHP_QUERY_RFC3986),
            'issued_at' => time() - 1, 'expires_at' => time() + 599];
    }
    private function signed(array $data): array
    {
        foreach ($data['grants'] ?? [] as $index => $grant) {
            $data['grants'][$index]['limits'] = (object) $grant['limits'];
        }
        $bytes = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['payload' => base64_encode($bytes), 'kid' => 'test-key', 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($this->pair)))];
    }
    private function connection(): array
    {
        return ['connection_id' => self::CONNECTION, 'installation_id' => $this->identity->id(), 'software_code' => 'howstack-bos',
            'connection_mode' => 'community', 'edition_code' => 'community', 'edition_name' => '社区版', 'license_valid' => false, 'license_no' => null,
            'license_expires_at' => null, 'account_name' => '测试官网会员', 'status' => 'active'];
    }
    private function response(array $data): Response { return new Response(200, [], json_encode(['code' => 'OK', 'data' => $data])); }
    /**
     * 构建带 HTTP 模拟和真实私有状态的客户端。
     * @param array<int, Response|\Throwable> $responses 模拟响应或失败
     * @param array<int, array<string, mixed>> $history 请求记录
     * @param array<string, mixed> $configuration 测试源站与能力配置
     * @return HlyqOfficialPlatformClient 隔离测试客户端
     */
    private function client(array $responses, array &$history, array $configuration = []): HlyqOfficialPlatformClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new HlyqOfficialPlatformClient(
            new Client(['handler' => $stack]),
            $this->store,
            $this->identity,
            array_replace(['origin' => 'https://official.example', 'download_hosts' => ['packages.example']], $configuration),
            '0.1.0',
        );
    }
    private function connectedClient(array $responses, array &$history): HlyqOfficialPlatformClient
    {
        $client = $this->client([$this->response(['connection' => $this->connection()]), $this->response($this->connection()), ...$responses], $history);
        $client->activate('HS-' . implode('-', array_fill(0, 6, 'AAAAAAAA'))); $client->synchronize();
        return $client;
    }
}
