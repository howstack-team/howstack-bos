<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\ManageChannelsService;
use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\channel\domain\ChannelCategories;
use app\modules\channel\infrastructure\DatabaseChannelPluginUsageInspector;
use app\modules\channel\infrastructure\DatabaseChannelRepository;
use app\modules\channel\infrastructure\InstalledChannelPlatformRegistry;
use app\modules\channel\validation\ChannelValidator;
use app\modules\plugin\infrastructure\DatabasePluginInstallationRepository;
use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 配置用例使用声明桩，事务、加密和生命周期使用真实隔离数据库；不代表外部通道已上线。 */
final class ChannelManagementTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testAllCategoriesPersistEncryptedConfigurationAndNeverReturnSecrets(): void
    {
        [$service, $repository, $actor] = $this->fixture();
        foreach (array_keys(ChannelCategories::LABELS) as $category) {
            $row = $service->save($actor, $category, null, $this->input($category));
            self::assertSame($category, $row['category']);
            self::assertObjectNotHasProperty('api_key', $row['configuration']);
            self::assertTrue($row['configured_secrets']->api_key);
            self::assertSame('private-test-value', $repository->configuration($category, $row['id'])['api_key']);
            $json = json_encode($row, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('private-test-value', $json);
            self::assertStringNotContainsString('configuration_encrypted', $json);
            self::assertStringNotContainsString('private-test-value', (string) Db::table('channel_connections')->where('id', $row['id'])->value('configuration_encrypted'));
            $list = $service->list($actor, $category, []);
            self::assertSame(1, $list['total']);
            self::assertArrayNotHasKey('configuration', $list['data'][0]);
        }
        self::assertSame(7, Db::table('channel_category_settings')->count());
    }

    public function testBlankSecretsPreserveAndExplicitClearRequiresOptionalField(): void
    {
        [$service, $repository, $actor] = $this->fixture();
        $row = $service->save($actor, 'payment', null, $this->input('payment'));
        $input = $this->input('payment');
        $input['revision'] = $row['revision'];
        $input['configuration']['api_key'] = '';
        $row = $service->save($actor, 'payment', $row['id'], $input);
        self::assertSame('private-test-value', $repository->configuration('payment', $row['id'])['api_key']);
        $input['revision'] = $row['revision'];
        $input['clear_secrets'] = ['api_key'];
        $row = $service->save($actor, 'payment', $row['id'], $input);
        self::assertFalse($row['configured_secrets']->api_key);
        self::assertSame('', $repository->configuration('payment', $row['id'])['api_key']);
        $input['clear_secrets'] = ['endpoint'];
        $this->rejected(422, fn () => $service->save($actor, 'payment', $row['id'], $input));
    }

    public function testDefaultIsExclusiveAndDisableClearsItWithoutAutomaticFallback(): void
    {
        [$service, , $actor] = $this->fixture();
        $input = $this->input('mail');
        $input['status'] = 1;
        $input['is_default'] = true;
        $first = $service->save($actor, 'mail', null, $input);
        $second = $service->save($actor, 'mail', null, $input);
        self::assertFalse($service->detail($actor, 'mail', $first['id'])['is_default']);
        self::assertTrue($second['is_default']);
        $service->status($actor, 'mail', $second['id'], ['status' => 0, 'revision' => $second['revision']]);
        self::assertNull(Db::table('channel_category_settings')->where('category', 'mail')->value('default_channel_id'));
        self::assertFalse($service->detail($actor, 'mail', $first['id'])['is_default']);
        $input['status'] = 0;
        $this->rejected(422, fn () => $service->save($actor, 'mail', null, $input));
    }

    public function testCategoryScopeAndRevisionPreventCrossCategoryAndStaleWrites(): void
    {
        [$service, , $actor] = $this->fixture();
        $row = $service->save($actor, 'payment', null, $this->input('payment'));
        $this->rejected(404, fn () => $service->detail($actor, 'sms', $row['id']));
        $this->rejected(404, fn () => $service->save($actor, 'sms', $row['id'], $this->input('sms')));
        $this->rejected(404, fn () => $service->status($actor, 'sms', $row['id'], ['status' => 1, 'revision' => 1]));
        $this->rejected(404, fn () => $service->archive($actor, 'sms', $row['id'], ['revision' => 1]));
        $this->rejected(409, fn () => $service->status($actor, 'payment', $row['id'], ['status' => 1, 'revision' => 999]));
        $input = $this->input('payment');
        $input['plugin_id'] = 'sms_test';
        $input['revision'] = 1;
        $this->rejected(409, fn () => $service->save($actor, 'payment', $row['id'], $input));
        self::assertSame(1, Db::table('channel_connections')->count());
    }

    public function testDefaultPolicyIsEnforcedAndExistingBusinessBindingsArePreserved(): void
    {
        [$service, , $actor] = $this->fixture();
        foreach (array_keys(ChannelCategories::LABELS) as $category) {
            $catalog = $service->catalog($actor, $category, []);
            self::assertSame(ChannelCategories::DEFAULT_LABELS[$category] ?? null, $catalog['default_label']);
            $input = $this->input($category);
            $input['status'] = 1;
            $input['is_default'] = true;
            if (isset(ChannelCategories::DEFAULT_LABELS[$category])) {
                self::assertTrue($service->save($actor, $category, null, $input)['is_default']);
                continue;
            }
            $this->rejected(422, fn () => $service->configure($actor, $category, $input['plugin_id'], $input));
            $input['is_default'] = false;
            $row = $service->configure($actor, $category, $input['plugin_id'], $input);
            // 旧类别级引用保留在数据库，公开资料不把它误当作当前主用规则。
            Db::table('channel_category_settings')->where('category', $category)->update(['default_channel_id' => $row['id']]);
            self::assertFalse($service->detail($actor, $category, $row['id'])['is_default']);
            $input['is_default'] = true;
            $input['revision'] = $row['revision'];
            $this->rejected(422, fn () => $service->updateConfiguration($actor, $category, $row['id'], $input));
            self::assertSame($row['id'], Db::table('channel_category_settings')->where('category', $category)->value('default_channel_id'));
            self::assertSame($row['revision'], $service->detail($actor, $category, $row['id'])['revision']);
        }
    }

    public function testIndependentPermissionsDoNotGrantOtherCategoryOrWrites(): void
    {
        [$service] = $this->fixture();
        $viewer = new AdminAccount(1, 'viewer', '测试查看人', 'hidden', 1, null);
        self::assertSame(0, $service->list($viewer, 'payment', [])['total']);
        $this->rejected(403, fn () => $service->list($viewer, 'sms', []));
        $this->rejected(403, fn () => $service->save($viewer, 'payment', null, $this->input('payment')));
        $this->rejected(403, fn () => $service->status($viewer, 'payment', 1, ['status' => 1, 'revision' => 1]));
        $this->rejected(403, fn () => $service->archive($viewer, 'payment', 1, ['revision' => 1]));
        $this->rejected(422, fn () => $service->list($viewer, 'provider', []));
    }

    public function testFiltersCombineWithAndAndSortingAndPaginationAreServerSide(): void
    {
        [$service, , $actor] = $this->fixture();
        for ($i = 0; $i < 22; $i++) {
            $input = $this->input('payment');
            $input['name'] = $i % 2 === 0 ? '主通道' : '备用通道';
            $input['sort_order'] = $i;
            $input['status'] = $i % 3 === 0 ? 1 : 0;
            $service->save($actor, 'payment', null, $input);
        }
        $result = $service->list($actor, 'payment', ['name' => '主', 'status' => '1', 'sort_by' => 'sort_order', 'sort_order' => 'desc']);
        self::assertSame(4, $result['total']);
        self::assertSame([18, 12, 6, 0], array_column($result['data'], 'sort_order'));
        $result = $service->list($actor, 'payment', ['page' => 3, 'page_size' => 10]);
        self::assertCount(2, $result['data']);
        self::assertSame(22, $result['total']);
        $this->rejected(422, fn () => $service->list($actor, 'payment', ['sort_by' => 'configuration_encrypted']));
    }

    public function testTypedConfigurationRejectsUnknownFieldsAndSplitEnumValues(): void
    {
        [$service, $repository, $actor] = $this->fixture();
        $input = $this->input('payment');
        $input['configuration']['unknown'] = 'value';
        $this->rejected(422, fn () => $service->save($actor, 'payment', null, $input));
        unset($input['configuration']['unknown']);
        $input['configuration']['mode'] = 'a';
        $this->rejected(422, fn () => $service->save($actor, 'payment', null, $input));
        $input['configuration']['mode'] = 'a,b';
        $input['configuration']['count'] = '0';
        $this->rejected(422, fn () => $service->save($actor, 'payment', null, $input));
        $input['configuration']['count'] = 0;
        $row = $service->save($actor, 'payment', null, $input);
        self::assertSame(0, $repository->configuration('payment', $row['id'])['count']);
        self::assertFalse($repository->configuration('payment', $row['id'])['flag']);
    }

    public function testActiveChannelsProtectPluginLifecycleAndArchivedHistoryIsRetained(): void
    {
        [$service, , $actor, $installations, $packages] = $this->fixture();
        $input = $this->input('payment');
        $input['status'] = 1;
        $row = $service->save($actor, 'payment', null, $input);
        $this->rejected(409, fn () => $service->archive($actor, 'payment', $row['id'], ['revision' => $row['revision']]));
        $installation = $installations->all()['payment_test'];
        $this->rejected(409, fn () => $installations->transition($packages['payment_test'], 'disable', $installation['revision'], null));
        $installation = $installations->transition($packages['payment_test'], 'drain', $installation['revision'], null);
        $this->rejected(409, fn () => $service->save($actor, 'payment', null, $input));
        $row = $service->status($actor, 'payment', $row['id'], ['status' => 0, 'revision' => $row['revision']]);
        $service->archive($actor, 'payment', $row['id'], ['revision' => $row['revision']]);
        self::assertSame(0, $service->list($actor, 'payment', [])['total']);
        self::assertSame(1, Db::table('channel_connections')->count());
        $this->rejected(404, fn () => $service->detail($actor, 'payment', $row['id']));
        $installation = $installations->transition($packages['payment_test'], 'disable', $installation['revision'], null);
        $this->rejected(409, fn () => $installations->transition($packages['payment_test'], 'uninstall', $installation['revision'], null));
    }

    public function testRealPackageRegistryDoesNotExposeProviderAsAChannel(): void
    {
        $installations = new DatabasePluginInstallationRepository(new DatabaseTransactionManager());
        $packages = new LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        $registry = new InstalledChannelPlatformRegistry($packages, $installations, new Client());
        foreach (array_keys(ChannelCategories::LABELS) as $category) {
            self::assertCount(['payment' => 2, 'sms' => 4, 'mail' => 3, 'identity' => 2, 'storage' => 4, 'oauth' => 2, 'captcha' => 5][$category], $registry->definitions($category));
            self::assertNull($registry->definition($category, 'magic_cloud'));
        }
    }

    public function testControllerUsesRequestIdentityAndUnifiedResponseAndProtectsWrites(): void
    {
        [$service, , $actor] = $this->fixture();
        $context = new \app\modules\admin\auth\application\AdminAuthenticationContext();
        $delivery = $this->createStub(\app\modules\channel\contract\mail\MailDeliveryRepositoryInterface::class);
        $executor = $this->createStub(\app\modules\channel\contract\mail\MailAdapterExecutorInterface::class);
        $access = new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class));
        $mail = new \app\modules\channel\application\mail\SendMailService($delivery, $executor, $access, new ChannelValidator());
        $controller = new \app\modules\channel\controller\AdminChannelController($service, $context, new \app\shared\http\ApiResponse(), $mail);
        $request = new \support\Request("GET /api/admin/v1/channels/payment HTTP/1.1\r\nHost: example.test\r\n\r\n");
        $context->set($request, $actor);
        $response = $controller->index($request, 'payment');
        $body = json_decode($response->rawBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $body['code']);
        self::assertSame($body['request_id'], $response->getHeader('X-Request-Id'));

        $reflection = new \ReflectionClass($controller);
        self::assertSame([\app\modules\admin\auth\middleware\AdminAuthMiddleware::class],
            $reflection->getAttributes(\support\annotation\Middleware::class)[0]->getArguments());
        foreach (['configure', 'update', 'status', 'testMail'] as $method) {
            self::assertSame([\app\shared\http\middleware\SameOriginWriteMiddleware::class],
                $reflection->getMethod($method)->getAttributes(\support\annotation\Middleware::class)[0]->getArguments());
        }
    }

    public function testAuditSummaryNeverIncludesConnectionConfiguration(): void
    {
        $input = $this->input('payment');
        $input['description'] = '不要记录的自由备注';
        $sanitizer = new \app\modules\admin\operation\application\OperationLogPayloadSanitizer();
        $json = $sanitizer->params('channels', '12', $input);
        $summary = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(12, $summary['target_id']);
        self::assertSame('payment_test', $summary['plugin_id']);
        self::assertArrayNotHasKey('configuration', $summary);
        self::assertArrayNotHasKey('description', $summary);
        self::assertStringNotContainsString('private-test-value', $json);
    }

    public function testMysqlMigrationHasCommentsAndSevenInitializedCategories(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('中文注释仅在真实隔离MySQL测试库验证');
        }
        foreach (['channel_connections', 'channel_category_settings'] as $table) {
            $columns = Db::select('SHOW FULL COLUMNS FROM orm_' . $table);
            foreach ($columns as $column) {
                self::assertNotSame('', $column->Comment, $table . '.' . $column->Field);
            }
        }
        self::assertSame(7, Db::table('channel_category_settings')->count());
    }

    public function testCatalogShowsEveryUnconfiguredPluginWithoutWritingConnections(): void
    {
        [$service, , $actor] = $this->fixture();
        foreach (array_keys(ChannelCategories::LABELS) as $category) {
            $list = $service->catalog($actor, $category, []);
            self::assertSame(1, $list['total']);
            self::assertSame(0, $list['data'][0]['id']);
            self::assertSame(0, $list['data'][0]['revision']);
            self::assertSame(0, $list['data'][0]['status']);
            self::assertFalse($list['data'][0]['configured']);
            self::assertArrayNotHasKey('configuration', $list['data'][0]);
            self::assertArrayNotHasKey('package_hash', $service->platforms($actor, $category)[0]);
        }
        self::assertSame(0, Db::table('channel_connections')->count());
        self::assertSame(0, $service->catalog($actor, 'payment', ['status' => 1])['total']);
        self::assertSame(0, $service->catalog($actor, 'payment', ['name' => '不存在'])['total']);
    }

    public function testFixedPlatformConfigurationUsesUpdatePermissionAndRejectsDuplicateCreation(): void
    {
        [$service, $repository] = $this->fixture(['admin.channels.payment.view', 'admin.channels.payment.update']);
        $editor = new AdminAccount(1, 'editor', '测试配置人', 'hidden', 1, null);
        $input = $this->input('payment');
        unset($input['name'], $input['plugin_id']);
        $row = $service->configure($editor, 'payment', 'payment_test', $input);
        self::assertTrue($row['configured']);
        self::assertSame('payment_test', $row['plugin_id']);
        self::assertSame('测试服务平台', $row['name']);
        $this->rejected(409, fn () => $service->configure($editor, 'payment', 'payment_test', $input));
        self::assertSame(1, Db::table('channel_connections')->count());
        $this->rejected(403, fn () => $service->configure($editor, 'sms', 'sms_test', $this->input('sms')));
        $input['revision'] = $row['revision'];
        $input['name'] = '不能改成任意名称';
        $input['plugin_id'] = 'sms_test';
        $input['configuration']['api_key'] = '';
        $row = $service->updateConfiguration($editor, 'payment', $row['id'], $input);
        self::assertSame('payment_test', $row['plugin_id']);
        self::assertSame('测试服务平台', $row['name']);
        self::assertSame('private-test-value', $repository->configuration('payment', $row['id'])['api_key']);
        $this->rejected(409, fn () => $service->updateConfiguration($editor, 'payment', $row['id'], $input));
        self::assertSame(1, $service->catalog($editor, 'payment', [])['total']);
        $row = $service->status($editor, 'payment', $row['id'], ['revision' => $row['revision'], 'status' => 1]);
        self::assertSame(1, $row['status']);
        $row = $service->status($editor, 'payment', $row['id'], ['revision' => $row['revision'], 'status' => 0]);
        self::assertSame(0, $row['status']);
        self::assertSame(1, Db::table('channel_connections')->count());
    }

    public function testCatalogPreservesExistingMultipleConnectionsAndDefaultBinding(): void
    {
        [$service, , $actor] = $this->fixture();
        $first = $service->save($actor, 'mail', null, $this->input('mail'));
        $input = $this->input('mail');
        $input['name'] = '保留默认配置';
        $input['status'] = 1;
        $input['is_default'] = true;
        $second = $service->save($actor, 'mail', null, $input);
        $rows = $service->catalog($actor, 'mail', [])['data'];
        self::assertSame([$first['id'], $second['id']], array_column($rows, 'id'));
        self::assertTrue($rows[1]['is_default']);
        $this->rejected(409, fn () => $service->configure($actor, 'mail', 'mail_test', $this->input('mail')));
        self::assertSame($second['id'], Db::table('channel_category_settings')->where('category', 'mail')->value('default_channel_id'));
        self::assertSame(2, Db::table('channel_connections')->count());
    }

    public function testRealCatalogIncludesDisabledAndUninstalledPluginsButExcludesProviders(): void
    {
        $installations = new DatabasePluginInstallationRepository(new DatabaseTransactionManager());
        $packages = new LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        $registry = new InstalledChannelPlatformRegistry($packages, $installations, new Client());
        foreach (['payment' => 2, 'sms' => 4, 'mail' => 3, 'identity' => 2, 'storage' => 4, 'oauth' => 2, 'captcha' => 5] as $category => $count) {
            $catalog = $registry->catalog($category);
            self::assertCount($count, $catalog);
            self::assertNotContains('magic_cloud', array_column($catalog, 'id'));
            foreach ($catalog as $platform) {
                self::assertTrue($platform['platform_available']);
            }
        }
        $smtp = $installations->all()['smtp'];
        $installations->transition($packages->packages()['smtp'], 'disable', $smtp['revision'], null);
        self::assertCount(2, $registry->definitions('mail'));
        self::assertNull($registry->definition('mail', 'smtp'));
        Db::table('business_plugin_installations')->where('plugin_id', 'aliyun_dm')->delete();
        self::assertCount(1, $registry->definitions('mail'));
        self::assertFalse(array_column($registry->catalog('mail'), null, 'id')['aliyun_dm']['platform_available']);
        self::assertCount(3, $registry->catalog('mail'));
    }

    /** @return array<int, mixed> 隔离库真实依赖与仅用于配置声明的桩 */
    private function fixture(array $permissions = ['admin.channels.payment.view']): array
    {
        $installations = new DatabasePluginInstallationRepository(new DatabaseTransactionManager(), [new DatabaseChannelPluginUsageInspector()]);
        $packages = [];
        foreach (array_keys(ChannelCategories::LABELS) as $category) {
            $id = $category . '_test';
            $packages[$id] = [
                'id' => $id, 'name' => '测试服务平台', 'category' => $category, 'version' => '1.0.0', 'package_hash' => hash('sha256', $id),
                'configuration_fields' => [
                    ['key' => 'endpoint', 'label' => '服务地址', 'type' => 'url', 'required' => true, 'max_length' => 512],
                    ['key' => 'api_key', 'label' => '密钥', 'type' => 'password', 'required' => false, 'max_length' => 128],
                    ['key' => 'count', 'label' => '次数', 'type' => 'integer', 'required' => true, 'minimum' => 0, 'maximum' => 5],
                    ['key' => 'flag', 'label' => '开关', 'type' => 'boolean', 'required' => true],
                    ['key' => 'mode', 'label' => '方式', 'type' => 'select', 'required' => true, 'options' => [['label' => '组合方式', 'value' => 'a,b']]],
                ],
            ];
            $installed = $installations->transition($packages[$id], 'install', 0, null);
            $installations->transition($packages[$id], 'enable', $installed['revision'], null);
        }
        $registry = $this->createStub(ChannelPlatformRegistryInterface::class);
        $registry->method('definition')->willReturnCallback(static fn (string $category, string $id): ?array => isset($packages[$id]) && $packages[$id]['category'] === $category ? $packages[$id] : null);
        $registry->method('definitions')->willReturnCallback(static fn (string $category): array => array_values(array_filter($packages, static fn (array $package): bool => $package['category'] === $category)));
        $registry->method('catalog')->willReturnCallback(static fn (string $category): array => array_values(array_map(
            static fn (array $package): array => $package + ['platform_available' => true],
            array_filter($packages, static fn (array $package): bool => $package['category'] === $category))));
        $registry->method('normalize')->willReturnCallback(static fn (string $category, string $id, array $values): array => $values);
        $repository = new DatabaseChannelRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), new DatabaseTransactionManager(), $registry, $installations);
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([['permissions' => $permissions]]);
        $actor = new AdminAccount(1, 'fixture', '测试管理员', 'hidden', 1, null, true);
        return [new ManageChannelsService(new AdminAccessControlService($grants), $repository, $registry, new ChannelValidator()), $repository, $actor, $installations, $packages];
    }

    /** @param string $category 类别 @return array<string, mixed> 测试请求 */
    private function input(string $category): array
    {
        return ['name' => '测试通道', 'plugin_id' => $category . '_test', 'configuration' => [
            'endpoint' => 'https://channel.example.test', 'api_key' => 'private-test-value', 'count' => 0, 'flag' => false, 'mode' => 'a,b',
        ], 'status' => 0, 'is_default' => false, 'sort_order' => 0, 'description' => '', 'revision' => 0];
    }

    /** @param int $status 期望状态 @param callable(): mixed $operation 请求 @return void */
    private function rejected(int $status, callable $operation): void
    {
        try {
            $operation();
            self::fail('请求应被拒绝');
        } catch (BusinessException $error) {
            self::assertSame($status, $error->httpStatus(), $error->getMessage());
        }
    }
}
