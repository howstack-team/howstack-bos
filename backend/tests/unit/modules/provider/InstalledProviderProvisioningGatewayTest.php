<?php

declare(strict_types=1);
namespace tests\unit\modules\provider;
use app\modules\provider\contract\{ProviderRepositoryInterface, ProviderAdapterRegistryInterface, ProviderAdapterInterface, ProviderProvisioningPreviewInterface};
use app\modules\provider\infrastructure\InstalledProviderProvisioningGateway;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use app\shared\plugin\dto\provider\ProvisionResult;
use app\shared\plugin\exception\PluginException;
use PHPUnit\Framework\TestCase;
final class InstalledProviderProvisioningGatewayTest extends TestCase
{
    public function testExecutionPinsOriginalConnectionAndStopsOnPluginReplacement(): void
    {
        $original = ['api_url' => 'https://original.example.test', 'password' => 'original-secret'];
        $current = $original;
        $repo = $this->createStub(ProviderRepositoryInterface::class);
        $repo->method('find')->willReturn(['id' => 1, 'adapter_id' => 'fixture', 'status' => 1, 'requires_retest' => false, 'revision' => 7]);
        $repo->method('credentialsAtRevision')->willReturnCallback(static function () use (&$current): array { return $current; });
        $hash = str_repeat('a', 64);
        $adapters = $this->createStub(ProviderAdapterRegistryInterface::class);
        $adapters->method('definition')->willReturnCallback(static function () use (&$hash): array { return ['id' => 'fixture', 'accepting' => true, 'manifest_hash' => $hash, 'capabilities' => ['production_ready' => true, 'product_types' => ['cloud_server'], 'type_version' => '2.0.0']]; });
        $adapter = $this->createMock(ProviderAdapterInterface::class); $adapter->method('normalizeConfiguration')->willReturnArgument(0);
        $adapter->expects(self::once())->method('provision')->with($original, self::anything(), false)->willReturn(new ProvisionResult('active', 'remote-1'));
        $adapters->method('get')->willReturn($adapter);
        $mapping = $this->createStub(ProviderProvisioningPreviewInterface::class); $mapping->method('build')->willReturn(['configuration' => ['quantity' => 1], 'provider_parameters' => []]);
        $gateway = new InstalledProviderProvisioningGateway($repo, $adapters, $mapping, new SodiumSensitiveDataCipher(str_repeat('test-key', 8)), $this->createStub(\app\modules\plugin\contract\PluginInstallationRepositoryInterface::class));
        $binding = ['connection_id' => 1, 'capability_snapshot' => ['manifest_hash' => $hash, 'connection_revision' => 7]];
        $capture = $gateway->capture($binding, ['zone' => ['id' => 1], 'product_type' => 'cloud_server', 'contract_version' => '2.0.0']);
        self::assertStringNotContainsString('original-secret', $capture['connection_encrypted']);
        $current = ['api_url' => 'https://replacement.example.test', 'password' => 'changed'];
        $task = $capture + ['request_key' => 'bos-fixed', 'external_id' => null, 'operation' => 'create', 'context' => ['delivery_binding' => $binding, 'provisioning' => []]];
        self::assertSame('active', $gateway->execute($task, 'Initial@123')->status);
        $hash = str_repeat('b', 64);
        try { $gateway->execute($task, 'Initial@123'); self::fail('不能用不同插件包重放'); } catch (PluginException $e) { self::assertSame('adapter_changed', $e->reason); }
    }
}
