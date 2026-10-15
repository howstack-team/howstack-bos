<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\infrastructure\DatabaseComputeResourceRepository;
use app\modules\provider\application\ManageProviderConnectionsService;
use app\modules\provider\infrastructure\{DatabaseProviderRepository, LocalProviderAdapterRegistry};
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实持久化和第二个本地插件验证适配器选择；HTTP 使用受控响应。 */
final class ProviderAdapterSelectionTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as setUpDatabase; tearDown as tearDownDatabase; }
    private string $pluginsPath;
    private array $manifest;

    protected function setUp(): void
    {
        $this->setUpDatabase();
        require_once dirname(__DIR__) . '/fixtures/sample_provider/ProviderAdapter.php';
        $this->pluginsPath = sys_get_temp_dir() . '/howstack-plugins-' . bin2hex(random_bytes(6));
        mkdir($this->pluginsPath . '/provider/sample_provider', 0700, true);
        copy(dirname(__DIR__) . '/fixtures/sample_provider/ProviderAdapter.php', $this->pluginsPath . '/provider/sample_provider/ProviderAdapter.php');
        $this->manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->manifest = array_replace($this->manifest, ['id' => 'sample_provider', 'name' => '测试供应插件']);
        $this->manifest['provider']['catalogs'] = [['resource_kind' => 'zones', 'kind' => 'locations', 'label' => '部署位置']];
        $this->manifest['configuration_fields'] = [
            ['key' => 'endpoint', 'label' => '服务地址', 'type' => 'url', 'required' => true, 'max_length' => 512],
            ['key' => 'api_key', 'label' => '密钥', 'type' => 'password', 'required' => true, 'max_length' => 2048],
        ];
        $this->manifest['network'] = ['base_url_field' => 'endpoint', 'endpoints' => [['method' => 'GET', 'path' => '/health'], ['method' => 'GET', 'path' => '/locations']]];
        $this->writeManifest();
    }

    protected function tearDown(): void
    {
        if (isset($this->pluginsPath)) {
            foreach (['ProviderAdapter.php', 'plugin.json', 'configuration.schema.json'] as $file) {
                unlink($this->pluginsPath . '/provider/sample_provider/' . $file);
            }
            rmdir($this->pluginsPath . '/provider/sample_provider');
            rmdir($this->pluginsPath . '/provider');
            rmdir($this->pluginsPath);
        }
        $this->tearDownDatabase();
    }

    public function testSelectedPluginIsPersistedAndUsedForTestAndCatalog(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200, [], '{"data":[{"external_id":"zone-a","name":"位置 A","metadata":{}}],"total":1}')]));
        $stack->push(Middleware::history($history));
        [$service, $repository, $actor] = $this->services(new Client(['handler' => $stack]));
        self::assertSame('sample_provider', $service->adapters($actor)[0]['id']);
        self::assertSame('1.0', $service->adapters($actor)[0]['sdk_version']);
        $row = $service->save($actor, null, $this->input());
        self::assertSame('sample_provider', $row['adapter_id']);
        self::assertSame('测试供应插件', $row['adapter_name']);
        self::assertSame('https://sample.example.test', $row['configuration']->endpoint);
        self::assertObjectNotHasProperty('api_key', $row['configuration']);
        self::assertTrue($row['configured_secrets']->api_key);
        self::assertArrayNotHasKey('ignored', $repository->credentials($row['id']));
        self::assertStringNotContainsString('sample-secret', (string) Db::table('provider_connections')->value('credentials_encrypted'));
        $row = $service->test($actor, $row['id']);
        self::assertFalse($row['requires_retest']);
        $result = $service->catalog($actor, $row['id'], 'locations', ['page' => 1, 'per_page' => 10]);
        self::assertSame('zone-a', $result['data'][0]['external_id']);
        self::assertSame('/health', $history[0]['request']->getUri()->getPath());
        self::assertSame('/locations', $history[1]['request']->getUri()->getPath());
        self::assertSame('sample-secret', $history[1]['request']->getHeaderLine('X-Test-Key'));
        self::assertSame('page=1&per_page=10', $history[1]['request']->getUri()->getQuery());
        self::assertSame('locations', $repository->catalogEntries($row['id'])[0]['kind']);
        $values = $this->input(); $values['revision'] = $row['revision']; $values['configuration']['api_key'] = '';
        $saved = $service->save($actor, $row['id'], $values);
        self::assertSame('sample-secret', $repository->credentials($row['id'])['api_key']);
        self::assertFalse($saved['requires_retest']);
        $values['revision'] = $saved['revision']; $values['configuration']['endpoint'] = 'https://changed.example.test';
        self::assertTrue($service->save($actor, $row['id'], $values)['requires_retest']);
        self::assertSame([], $repository->catalogEntries($row['id']));
    }

    public function testUninstalledAdapterCannotBeCreated(): void
    {
        [$service, , $actor] = $this->services();
        $values = $this->input(); $values['adapter_id'] = 'uninstalled';
        try { $service->save($actor, null, $values); self::fail('应拒绝未安装插件'); }
        catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
        self::assertSame(0, Db::table('provider_connections')->count());
    }

    public function testEncryptedPluginConfigurationCanExceedOrdinaryTextCapacity(): void
    {
        $this->manifest['configuration_fields'] = [$this->manifest['configuration_fields'][0]];
        $values = $this->input(); $values['configuration'] = ['endpoint' => 'https://sample.example.test'];
        for ($index = 0; $index < 15; $index++) {
            $key = 'secret_' . $index;
            $this->manifest['configuration_fields'][] = ['key' => $key, 'label' => '密钥 ' . $index, 'type' => 'password', 'required' => true, 'max_length' => 2048];
            $values['configuration'][$key] = str_repeat('加密测试', 400);
        }
        $this->writeManifest();
        [$service, $repository, $actor] = $this->services();
        $row = $service->save($actor, null, $values);
        self::assertGreaterThan(65535, strlen((string) Db::table('provider_connections')->value('credentials_encrypted')));
        self::assertSame($values['configuration']['secret_14'], $repository->credentials($row['id'])['secret_14']);
        self::assertCount(15, (array) $row['configured_secrets']);
        self::assertSame(['endpoint' => 'https://sample.example.test'], (array) $row['configuration']);
    }

    public function testExistingConnectionCannotSwitchAdapter(): void
    {
        [$service, , $actor] = $this->services();
        $row = $service->save($actor, null, $this->input());
        $values = $this->input(); $values['adapter_id'] = 'magic_cloud'; $values['revision'] = $row['revision'];
        $this->expectException(BusinessException::class);
        $service->save($actor, $row['id'], $values);
    }

    public function testDisabledPluginDisappearsAndExistingConnectionCanStillBeDisabled(): void
    {
        [$service, $repository, $actor] = $this->services(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200)]))]));
        $row = $service->save($actor, null, $this->input()); $row = $service->test($actor, $row['id']);
        $row = $service->status($actor, $row['id'], $row['revision'], 1);
        $this->writeManifest(false);
        [$newService, $newRepository] = $this->services();
        self::assertSame([], $newService->adapters($actor));
        self::assertTrue($newRepository->find($row['id'])['requires_retest']);
        self::assertFalse($newRepository->find($row['id'])['adapter_available']);
        self::assertSame(0, $newService->status($actor, $row['id'], $row['revision'], 0)['status']);
        self::assertSame('sample-secret', $repository->credentials($row['id'])['api_key']);
    }

    public function testPluginUpgradeRequiresRetestAndClearsOldCatalog(): void
    {
        [$service, $repository, $actor] = $this->services(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200)]))]));
        $row = $service->save($actor, null, $this->input()); $row = $service->test($actor, $row['id']);
        $repository->cacheCatalog($row['id'], 'locations', $row['revision'], [['external_id' => 'old', 'name' => '旧目录', 'metadata' => []]]);
        $this->manifest['version'] = '1.2.0'; $this->writeManifest();
        [$updatedService, $updatedRepository] = $this->services(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200)]))]));
        self::assertTrue($updatedRepository->find($row['id'])['requires_retest']);
        try { $updatedService->catalog($actor, $row['id'], 'locations', ['page' => 1]); self::fail('应先重测'); }
        catch (BusinessException $e) { self::assertStringContainsString('测试', $e->getMessage()); }
        $new = $updatedService->test($actor, $row['id']);
        self::assertSame('1.2.0', $new['adapter_version']);
        self::assertSame([], $updatedRepository->catalogEntries($row['id']));
    }

    public function testUnsupportedSdkManifestIsRejected(): void
    {
        $this->manifest['requires']['provider_sdk'] = '9.0'; $this->writeManifest();
        [$service, , $actor] = $this->services();
        self::assertSame([], $service->adapters($actor));
    }

    public function testIncompatibleCoreVersionIsNotSelectable(): void
    {
        $this->manifest['requires']['core_min'] = '0.2.0'; $this->writeManifest();
        [$service, , $actor] = $this->services();
        self::assertSame([], $service->adapters($actor));
        $this->expectException(BusinessException::class);
        $service->save($actor, null, $this->input());
    }

    public function testReadPermissionDoesNotAllowConnectionCreation(): void
    {
        [$service] = $this->services();
        $actor = new AdminAccount(1, 'viewer', '查看用户', 'hidden', 1, null);
        self::assertCount(1, $service->adapters($actor));
        try { $service->save($actor, null, $this->input()); self::fail('应拒绝创建'); }
        catch (BusinessException $e) { self::assertSame(403, $e->httpStatus()); }
        self::assertSame(0, Db::table('provider_connections')->count());
    }

    public function testUnknownConfigurationIsRejectedWithoutSaving(): void
    {
        [$service, , $actor] = $this->services();
        $input = $this->input();
        $input['configuration']['unknown'] = 'not-supported';
        try {
            $service->save($actor, null, $input);
            self::fail('未知字段必须拒绝');
        } catch (BusinessException $error) {
            self::assertSame(422, $error->httpStatus());
        }
        self::assertSame(0, Db::table('provider_connections')->count());
    }

    public function testTypedConfigurationPreservesFalseZeroAndExplicitSecretClear(): void
    {
        $this->manifest['configuration_fields'][1]['required'] = false;
        $this->manifest['configuration_fields'][] = ['key' => 'count', 'label' => '数量', 'type' => 'integer', 'required' => true, 'minimum' => 0, 'maximum' => 5];
        $this->manifest['configuration_fields'][] = ['key' => 'flag', 'label' => '开关', 'type' => 'boolean', 'required' => true];
        $this->manifest['configuration_fields'][] = ['key' => 'mode', 'label' => '模式', 'type' => 'select', 'required' => true, 'options' => [['label' => '方式 A', 'value' => 'a']]];
        $this->writeManifest();
        [$service, $repo, $actor] = $this->services();
        $input = $this->input();
        $input['configuration'] += ['count' => 0, 'flag' => false, 'mode' => 'a'];
        $row = $service->save($actor, null, $input);
        self::assertSame(0, $repo->credentials($row['id'])['count']);
        self::assertFalse($repo->credentials($row['id'])['flag']);
        $input['revision'] = $row['revision'];
        $input['clear_secrets'] = ['api_key'];
        $input['configuration']['api_key'] = '';
        $row = $service->save($actor, $row['id'], $input);
        self::assertFalse($row['configured_secrets']->api_key);
        self::assertSame('', $repo->credentials($row['id'])['api_key']);
    }

    /** @return array<int, mixed> 真实仓储与测试插件 */
    private function services(?Client $http = null): array
    {
        $installations = new \app\modules\plugin\infrastructure\DatabasePluginInstallationRepository(new DatabaseTransactionManager());
        $registry = new LocalProviderAdapterRegistry($this->pluginsPath, '0.1.0', $http ?? new Client(), $installations);
        $repository = new DatabaseProviderRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), new DatabaseTransactionManager(), $registry, $installations);
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => ['admin.provider-connections.view']]]);
        return [new ManageProviderConnectionsService(new AdminAccessControlService($access), $repository, $registry, new DatabaseComputeResourceRepository()), $repository,
            new AdminAccount(1, 'fixture-admin', '测试管理员', 'hidden', 1, null, true)];
    }

    /** @return array<string, mixed> 新连接白名单 */
    private function input(): array
    {
        return ['name' => '测试连接', 'adapter_id' => 'sample_provider', 'configuration' => ['endpoint' => 'https://sample.example.test/', 'api_key' => 'sample-secret'], 'status' => 0, 'revision' => 0];
    }

    /** @param bool $enabled 插件启用 @return void 写入受控测试清单 */
    private function writeManifest(bool $enabled = true): void
    {
        $manifest = $this->manifest;
        $fields = $manifest['configuration_fields'];
        unset($manifest['configuration_fields']);
        file_put_contents($this->pluginsPath . '/provider/sample_provider/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($this->pluginsPath . '/provider/sample_provider/configuration.schema.json', json_encode(['schema_version' => '1.0', 'scope' => 'connection', 'fields' => $fields], JSON_THROW_ON_ERROR));
        $packages = new \app\modules\plugin\infrastructure\LocalPluginPackageRegistry($this->pluginsPath, '0.1.0');
        $package = $packages->packages()['sample_provider'] ?? null;
        $repository = new \app\modules\plugin\infrastructure\DatabasePluginInstallationRepository(new DatabaseTransactionManager());
        $record = $repository->all()['sample_provider'] ?? null;
        if (!$enabled) {
            if ($record !== null) {
                $repository->transition($package, 'disable', $record['revision'], null);
            }
            return;
        }
        if ($package === null) {
            return;
        }
        if ($record === null) {
            $record = $repository->transition($package, 'install', 0, null);
        } elseif ($record['package_hash'] !== $package['package_hash']) {
            if ($record['state'] === 'enabled') {
                $record = $repository->transition($package, 'drain', $record['revision'], null);
            }
            $record = $repository->transition($package, 'upgrade', $record['revision'], null);
        }
        if ($record['state'] !== 'enabled') {
            $repository->transition($package, 'enable', $record['revision'], null);
        }
    }
}
