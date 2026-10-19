<?php

declare(strict_types=1);

namespace tests\unit\modules\provider;

use app\modules\provider\contract\{ProviderAdapterInterface, ProviderAdapterRegistryInterface, ProviderInstanceOperationsAdapterInterface, ProviderLifecycleAdapterInterface};
use app\modules\provider\infrastructure\InstalledProviderLifecycleGateway;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use app\shared\plugin\dto\provider\{InstanceOperationRequest, LifecycleResult};
use app\shared\plugin\exception\PluginException;
use PHPUnit\Framework\TestCase;

/** 运维能力不能授予续费能力，原连接和插件摘要必须保持固定。 */
final class InstalledProviderLifecycleGatewayTest extends TestCase
{
    public function testOperationOnlyAdapterCannotAuthorizeRenewalAndQueryNeverContainsPassword(): void
    {
        $cipher = new SodiumSensitiveDataCipher(str_repeat('secret', 16));
        $registry = $this->createStub(ProviderAdapterRegistryInterface::class);
        $hash = str_repeat('a', 64);
        $registry->method('definition')->willReturnCallback(static function () use (&$hash): array {
            return ['manifest_hash' => $hash];
        });
        $adapter = $this->createMockForIntersectionOfInterfaces([ProviderAdapterInterface::class, ProviderInstanceOperationsAdapterInterface::class, ProviderLifecycleAdapterInterface::class]);
        $adapter->method('instanceOperationActions')->willReturn(['reset_password']);
        $adapter->method('supportsLifecycle')->willReturn(false);
        $calls = [];
        $adapter->expects(self::exactly(2))->method('instanceOperation')->willReturnCallback(static function (array $config, InstanceOperationRequest $request, bool $query) use (&$calls): LifecycleResult {
            self::assertSame(['api_url' => 'https://original.example.test', 'password' => 'original-connection'], $config);
            self::assertSame($query ? null : 'Test@123456', $request->password);
            self::assertSame($query ? '456' : null, $request->providerTaskId);
            $calls[] = $query;
            return new LifecycleResult('pending', providerTaskId: '456');
        });
        $registry->method('get')->willReturn($adapter);
        $source = ['adapter_id' => 'fixture', 'package_hash' => $hash, 'external_id' => '123', 'request_key' => 'bos-original',
            'connection_encrypted' => $cipher->encrypt(json_encode(['api_url' => 'https://original.example.test', 'password' => 'original-connection']))];
        $gateway = new InstalledProviderLifecycleGateway($registry, $cipher);
        self::assertSame(['reset_password'], $gateway->actions($source));
        try {
            $gateway->assertSupported($source);
            self::fail('运维能力不能授权扣款续费');
        } catch (PluginException $error) {
            self::assertSame('lifecycle_unsupported', $error->reason);
        }
        $task = ['source' => $source, 'request_key' => 'fixed-operation', 'action' => 'reset_password', 'operation_context' => [],
            'query_only' => false, 'password_encrypted' => $cipher->encrypt('Test@123456'), 'credential_expires_at' => date('Y-m-d H:i:s', time() + 1000), 'provider_task_id' => null];
        $gateway->execute($task);
        $gateway->execute(array_replace($task, ['query_only' => true, 'password_encrypted' => null, 'provider_task_id' => '456']));
        self::assertSame([false, true], $calls);
        $hash = str_repeat('b', 64);
        $this->expectException(PluginException::class);
        $gateway->execute($task);
    }
}
