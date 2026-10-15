<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\application\ManageChannelsService;
use app\modules\channel\application\payment\PaymentChannelService;
use app\modules\channel\infrastructure\DatabaseChannelRepository;
use app\modules\channel\infrastructure\InstalledChannelPlatformRegistry;
use app\modules\channel\infrastructure\InstalledTransactionAdapterFactory;
use app\modules\channel\validation\ChannelValidator;
use app\modules\plugin\infrastructure\DatabasePluginInstallationRepository;
use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use app\shared\plugin\dto\payment\PaymentRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实插件登记、配置加密及宿主执行边界；使用隔离数据库，不发起真实交易。 */
final class AlipayChannelManagementTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testFixedCategoriesExposeRealConfigurationAndEncryptPrivateKey(): void
    {
        [$manage, $repository, $actor, $platforms] = $this->fixture();
        self::assertSame(['alipay_order_code', 'wechat_native'], array_column($platforms->definitions('payment'), 'id'));
        self::assertSame(['alipay_identity', 'tencent_faceid'], array_column($platforms->definitions('identity'), 'id'));
        self::assertNull($platforms->definition('payment', 'alipay_identity'));
        foreach (['payment', 'identity'] as $category) {
            $input = $this->input($category);
            $row = $manage->save($actor, $category, null, $input);
            self::assertTrue($row['configured_secrets']->private_key);
            self::assertObjectNotHasProperty('private_key', $row['configuration']);
            self::assertStringNotContainsString($input['configuration']['private_key'], json_encode($row, JSON_THROW_ON_ERROR));
            $stored = $repository->configuration($category, $row['id']);
            self::assertNotSame('', $stored['private_key']);
            self::assertStringNotContainsString($stored['private_key'], (string) Db::table('channel_connections')->where('id', $row['id'])->value('configuration_encrypted'));
            $input['revision'] = $row['revision'];
            $input['configuration']['private_key'] = '';
            $manage->save($actor, $category, $row['id'], $input);
            self::assertSame($stored['private_key'], $repository->configuration($category, $row['id'])['private_key']);
        }
    }

    public function testInvalidKeyAndCrossCategoryPlatformCannotBeSaved(): void
    {
        [$manage, , $actor] = $this->fixture();
        $input = $this->input('payment');
        $input['configuration']['alipay_public_key'] = 'invalid-key';
        $this->rejected(422, fn () => $manage->save($actor, 'payment', null, $input));
        $input = $this->input('payment');
        $input['plugin_id'] = 'alipay_identity';
        $this->rejected(409, fn () => $manage->save($actor, 'payment', null, $input));
        self::assertSame(0, Db::table('channel_connections')->count());
    }

    public function testStaleOrUnavailableChannelRejectsBeforeExecutingNetworkRequest(): void
    {
        [$manage, $repository, $actor, $platforms, $packages, $installations] = $this->fixture();
        $row = $manage->save($actor, 'payment', null, $this->input('payment'));
        $mock = new MockHandler([]);
        $factory = new InstalledTransactionAdapterFactory($packages, $platforms, new Client(['handler' => $mock]));
        $execution = new PaymentChannelService(new ChannelExecutionConfiguration($repository), $factory);
        $request = new PaymentRequest('PAY-TEST', 100, '测试订单', 'https://merchant.example.test/notify');
        $this->rejected(409, fn () => $execution->create($row['id'], 999, $request));
        $row = $manage->status($actor, 'payment', $row['id'], ['status' => 0, 'revision' => $row['revision']]);
        $this->rejected(409, fn () => $execution->create($row['id'], $row['revision'], $request));
        $record = $installations->all()['alipay_order_code'];
        $installations->transition($packages->packages()['alipay_order_code'], 'disable', $record['revision'], null);
        $this->rejected(409, fn () => $execution->query($row['id'], $row['revision'], $request));
        self::assertSame(0, $mock->count());
    }

    /** @return array<int, mixed> 隔离数据库中的真实注册器与用例 */
    private function fixture(): array
    {
        $transactions = new DatabaseTransactionManager();
        $installations = new DatabasePluginInstallationRepository($transactions);
        $packages = new LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        foreach (['alipay_order_code', 'alipay_identity'] as $id) {
            $package = $packages->packages()[$id];
            if (($installations->all()[$id]['state'] ?? null) === 'enabled') {
                continue;
            }
            $installed = $installations->transition($package, 'install', 0, null);
            $installations->transition($package, 'enable', $installed['revision'], null);
        }
        $platforms = new InstalledChannelPlatformRegistry($packages, $installations, new Client());
        $repository = new DatabaseChannelRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), $transactions, $platforms, $installations);
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $actor = new AdminAccount(1, 'fixture', '测试管理员', 'hidden', 1, null, true);
        $manage = new ManageChannelsService(new AdminAccessControlService($grants), $repository, $platforms, new ChannelValidator());
        return [$manage, $repository, $actor, $platforms, $packages, $installations];
    }

    /** @param string $category 通道类别
     * @return array<string, mixed> 临时生成的测试密钥与配置，不是真实商户
     */
    private function input(string $category): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $configuration = ['app_id' => '2021000000000001', 'private_key' => $private,
            'alipay_public_key' => openssl_pkey_get_details($key)['key'], 'sandbox' => true];
        if ($category === 'payment') {
            $configuration['seller_id'] = '2088000000000001';
        }
        return ['name' => '测试支付宝', 'plugin_id' => $category === 'payment' ? 'alipay_order_code' : 'alipay_identity',
            'configuration' => $configuration, 'status' => 1, 'is_default' => false, 'sort_order' => 0, 'description' => '', 'revision' => 0];
    }

    /** @param int $status 期望错误状态
     * @param callable(): mixed $operation 待拒绝的操作
     * @return void
     */
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
