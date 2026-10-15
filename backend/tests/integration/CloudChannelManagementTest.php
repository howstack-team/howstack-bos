<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\ManageChannelsService;
use app\modules\channel\infrastructure\DatabaseChannelRepository;
use app\modules\channel\infrastructure\InstalledChannelPlatformRegistry;
use app\modules\channel\infrastructure\InstalledTransactionAdapterFactory;
use app\modules\channel\validation\ChannelValidator;
use app\modules\plugin\infrastructure\DatabasePluginInstallationRepository;
use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实插件清单、固定类别、后台配置加密和宿主加载器，使用隔离 MySQL。 */
final class CloudChannelManagementTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testEveryNewPluginCanBeConfiguredAndSecretsNeverAppearInPublicDetails(): void
    {
        [$manage, $repository, $actor, $platforms, $packages] = $this->fixture();
        foreach (['sms' => 4, 'mail' => 3, 'identity' => 2, 'payment' => 2, 'storage' => 4, 'oauth' => 2, 'captcha' => 5] as $category => $count) {
            self::assertCount($count, $platforms->definitions($category));
        }
        $configs = $this->configurations();
        foreach ($configs as $id => $configuration) {
            $package = $packages->packages()[$id];
            $category = $package['category'];
            $input = ['name' => $package['name'], 'plugin_id' => $id, 'configuration' => $configuration,
                'status' => 1, 'is_default' => false, 'sort_order' => 0, 'description' => '', 'revision' => 0];
            $row = $manage->save($actor, $category, null, $input);
            self::assertTrue($row['platform_available']);
            self::assertTrue($row['configuration_current']);
            $secretKeys = array_column(array_filter($package['configuration_fields'], static fn (array $field): bool => $field['type'] === 'password'), 'key');
            $plaintext = $repository->configuration($category, $row['id']);
            foreach ($secretKeys as $key) {
                self::assertObjectNotHasProperty($key, $row['configuration']);
                self::assertSame($configuration[$key], $plaintext[$key]);
                self::assertStringNotContainsString($configuration[$key], (string) Db::table('channel_connections')->where('id', $row['id'])->value('configuration_encrypted'));
                $input['configuration'][$key] = '';
            }
            if ($secretKeys !== []) {
                $input['revision'] = $row['revision'];
                $manage->save($actor, $category, $row['id'], $input);
                self::assertSame($plaintext, $repository->configuration($category, $row['id']));
            }
        }
        self::assertSame(19, Db::table('channel_connections')->count());
    }

    public function testFactoryLoadsFixedContractsAndRejectsCrossCategoryAndDisabledPackages(): void
    {
        [, , , $platforms, $packages, $installations] = $this->fixture();
        $factory = new InstalledTransactionAdapterFactory($packages, $platforms, new Client(['handler' => new MockHandler([])]));
        foreach ($this->configurations() as $id => $configuration) {
            $category = $packages->packages()[$id]['category'];
            if ($category !== 'mail') {
                $adapter = $factory->{$category}($id);
                self::assertSame($id, explode('\\', $adapter::class)[2]);
            }
        }
        try {
            $factory->oauth('aliyun_sms');
            self::fail('不能跨类别执行插件');
        } catch (\app\shared\plugin\exception\PluginException $error) {
            self::assertSame('plugin_unavailable', $error->reason);
        }
        $row = $installations->all()['qq_login'];
        $installations->transition($packages->packages()['qq_login'], 'disable', $row['revision'], null);
        $this->expectException(\app\shared\plugin\exception\PluginException::class);
        $factory->oauth('qq_login');
    }

    public function testDependencyContainerProvidesRestrictedLocalStorageCapability(): void
    {
        [, , , $platforms, $packages] = $this->fixture();
        $store = $this->createMock(\app\shared\plugin\contract\LocalObjectStoreInterface::class);
        $store->expects(self::once())->method('put')->with('uploads', self::isInstanceOf(\app\shared\plugin\dto\storage\ObjectUpload::class));
        $container = (new \DI\ContainerBuilder())->addDefinitions(dirname(__DIR__, 2) . '/config/dependence.php')->addDefinitions([
            \app\modules\plugin\contract\PluginPackageRegistryInterface::class => $packages,
            \app\modules\channel\contract\ChannelPlatformRegistryInterface::class => $platforms,
            \GuzzleHttp\ClientInterface::class => new Client(['handler' => new MockHandler([])]),
            \app\shared\plugin\contract\LocalObjectStoreInterface::class => $store,
        ])->build();
        $factory = $container->get(\app\modules\channel\contract\TransactionAdapterFactoryInterface::class);
        $result = $factory->storage('local_storage')->put(
            new \app\shared\plugin\dto\storage\ObjectUpload(new \app\shared\plugin\dto\storage\ObjectKey('test.txt'), 'test', 'text/plain'),
            new \app\shared\plugin\dto\Configuration(['namespace' => 'uploads']),
        );
        self::assertSame('stored', $result->status);
    }

    public function testDependencyContainerProvidesRestrictedImageCaptchaCapability(): void
    {
        [, , , $platforms, $packages] = $this->fixture();
        $image = $this->createMock(\app\shared\plugin\contract\ImageCaptchaInterface::class);
        $image->expects(self::once())->method('generate')->willReturn('data:image/jpeg;base64,fixture');
        $container = (new \DI\ContainerBuilder())->addDefinitions(dirname(__DIR__, 2) . '/config/dependence.php')->addDefinitions([
            \app\modules\plugin\contract\PluginPackageRegistryInterface::class => $packages,
            \app\modules\channel\contract\ChannelPlatformRegistryInterface::class => $platforms,
            \GuzzleHttp\ClientInterface::class => new Client(['handler' => new MockHandler([])]),
            \app\shared\plugin\contract\ImageCaptchaInterface::class => $image,
        ])->build();
        $factory = $container->get(\app\modules\channel\contract\TransactionAdapterFactoryInterface::class);
        $result = $factory->captcha('image_captcha')->initialize(
            new \app\shared\plugin\dto\captcha\CaptchaContext(str_repeat('a', 64), str_repeat('b', 64), 'test.login', '198.51.100.12'),
            new \app\shared\plugin\dto\Configuration(['length' => 5, 'width' => 160, 'height' => 50]),
        );
        self::assertSame('data:image/jpeg;base64,fixture', $result->parameters['image']);
    }

    public function testServerPathsAndRemoteEndpointsCannotBeInjectedIntoLocalConfiguration(): void
    {
        [$manage, , $actor] = $this->fixture();
        $this->expectException(BusinessException::class);
        $manage->save($actor, 'storage', null, ['name' => '非法路径', 'plugin_id' => 'local_storage',
            'configuration' => ['namespace' => '../../public'], 'status' => 1, 'is_default' => false,
            'sort_order' => 0, 'description' => '', 'revision' => 0]);
    }

    /**
     * @return array<int, mixed> 隔离数据库夹具
     */
    private function fixture(): array
    {
        $transactions = new DatabaseTransactionManager();
        $installations = new DatabasePluginInstallationRepository($transactions);
        $packages = new LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        self::assertSame([], $packages->failures());
        foreach ($packages->packages() as $package) {
            $existing = $installations->all()[$package['id']] ?? null;
            if ($existing !== null && $existing['state'] === 'enabled') {
                self::assertSame($package['package_hash'], $existing['package_hash']);
                continue;
            }
            $row = $installations->transition($package, 'install', 0, null);
            $installations->transition($package, 'enable', $row['revision'], null);
        }
        $platforms = new InstalledChannelPlatformRegistry($packages, $installations, new Client());
        $repository = new DatabaseChannelRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), $transactions, $platforms, $installations);
        $actor = new AdminAccount(1, 'fixture', '测试管理员', 'hidden', 1, null, true);
        $manage = new ManageChannelsService(new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)), $repository, $platforms, new ChannelValidator());
        return [$manage, $repository, $actor, $platforms, $packages, $installations];
    }

    /**
     * @return array<string, array<string, mixed>> 测试凭据，全部为虚构内容
     */
    private function configurations(): array
    {
        $ali = ['access_key_id' => 'ALI_TEST_ID', 'access_key_secret' => 'ALI_TEST_SECRET'];
        $tc = ['secret_id' => 'TC_TEST_ID', 'secret_key' => 'TC_TEST_SECRET', 'region' => 'ap-guangzhou'];
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsa, $private);
        // 配置校验会统一 PEM 行宽，因此用规范化后的 PEM 校验加密保存和空密钥保留。
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/payment/wechat_native',
            'plugins\\payment\\wechat_native\\',
        );
        $wechat = (new \plugins\payment\wechat_native\PaymentAdapter())->validateConfiguration(new \app\shared\plugin\dto\Configuration([
            'app_id' => 'wx1234567890abcdef', 'mch_id' => '1234567890', 'merchant_serial' => str_repeat('A', 40),
            'platform_serial' => 'PUB_KEY_ID_TEST', 'private_key' => $private, 'platform_public_key' => openssl_pkey_get_details($rsa)['key'], 'api_v3_key' => str_repeat('k', 32),
        ]))->values;
        return [
            'aliyun_sms' => $ali + ['sign_name' => '测试签名'],
            'tencent_sms' => $tc + ['sdk_app_id' => '1400000000', 'sign_name' => '测试签名'],
            'submail_sms' => ['app_id' => 'TEST_ID', 'app_key' => 'TEST_SECRET'],
            'smsbao' => ['username' => 'TEST_USER', 'api_key' => 'TEST_SECRET'],
            'aliyun_dm' => $ali + ['region' => 'cn-hangzhou', 'from_email' => 'from@example.test', 'from_name' => '测试'],
            'tencent_ses' => $tc + ['template_id' => 1, 'from_email' => 'from@example.test'],
            'tencent_faceid' => $tc + ['rule_id' => '1'],
            'aliyun_oss' => $ali + ['region' => 'cn-hangzhou', 'bucket' => 'test-bucket'],
            'tencent_cos' => ['access_key_id' => 'TC_TEST_ID', 'access_key_secret' => 'TC_TEST_SECRET', 'region' => 'ap-guangzhou', 'bucket' => 'test-1250000000'],
            'qiniu_storage' => ['access_key' => 'QN_TEST_ID', 'secret_key' => 'QN_TEST_SECRET', 'region' => 'z0', 'bucket' => 'test', 'download_base' => 'https://files.example.test'],
            'local_storage' => ['namespace' => 'uploads'],
            'wechat_native' => $wechat,
            'wechat_login' => ['app_id' => 'wx1234567890abcdef', 'app_secret' => 'WX_TEST_SECRET', 'redirect_url' => 'https://merchant.example.test/oauth/wechat'],
            'image_captcha' => ['length' => 5, 'width' => 160, 'height' => 50],
            'geetest_v4' => ['captcha_id' => str_repeat('a', 32), 'captcha_key' => 'GT_TEST_SECRET'],
            'yidun_captcha' => ['captcha_id' => 'YD_TEST_ID', 'secret_id' => 'YD_TEST_SECRET_ID', 'secret_key' => 'YD_TEST_SECRET_KEY'],
            'tencent_captcha' => ['secret_id' => 'TC_TEST_ID', 'secret_key' => 'TC_TEST_SECRET', 'captcha_app_id' => '199999999', 'app_secret_key' => 'TC_CAPTCHA_TEST_SECRET'],
            'aliyun_captcha' => $ali + ['scene_id' => 'SCENE_TEST_ID', 'prefix' => 'PREFIX_TEST', 'region' => 'cn'],
            'qq_login' => ['app_id' => '12345678', 'app_secret' => 'QQ_TEST_SECRET', 'redirect_url' => 'https://merchant.example.test/oauth/qq'],
        ];
    }
}
