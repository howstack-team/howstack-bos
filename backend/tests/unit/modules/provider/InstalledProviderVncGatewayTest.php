<?php

declare(strict_types=1);

namespace tests\unit\modules\provider;

use app\modules\provider\contract\{ProviderAdapterInterface, ProviderAdapterRegistryInterface, ProviderVncAdapterInterface};
use app\modules\provider\infrastructure\InstalledProviderVncGateway;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use app\shared\plugin\dto\provider\{InstanceIdentity, VncConnection};
use app\shared\plugin\exception\PluginException;
use PHPUnit\Framework\TestCase;

/** 新VNC会话可使用已审核的同ID插件，实例身份和原账号始终固定，不改写或重放旧履约任务。 */
final class InstalledProviderVncGatewayTest extends TestCase
{
    public function testApprovedVncCapabilityUsesOriginalCredentialsAndRefusesUnavailablePlugin(): void
    {
        $cipher = new SodiumSensitiveDataCipher(str_repeat('secret', 16));
        $registry = $this->createStub(ProviderAdapterRegistryInterface::class);
        $enabled = true;
        $registry->method('definition')->willReturnCallback(static function () use (&$enabled): ?array {
            return $enabled ? ['manifest_hash' => str_repeat('b', 64)] : null;
        });
        $adapter = $this->createMockForIntersectionOfInterfaces([ProviderAdapterInterface::class, ProviderVncAdapterInterface::class]);
        $adapter->method('supportsVnc')->willReturn(true);
        $adapter->expects(self::once())->method('openVnc')->willReturnCallback(static function (array $config, InstanceIdentity $identity): VncConnection {
            self::assertSame(['api_url' => 'https://original.example.test', 'password' => 'original-account'], $config);
            self::assertSame('123', $identity->externalId);
            self::assertSame('original-hostname', $identity->hostname);
            return new VncConnection('wss://vnc.example.test/socket?token=fixture', 'vnc-only-secret');
        });
        $registry->method('get')->willReturn($adapter);
        $source = ['adapter_id' => 'fixture', 'package_hash' => str_repeat('a', 64), 'external_id' => '123', 'request_key' => 'original-hostname',
            'connection_encrypted' => $cipher->encrypt(json_encode(['api_url' => 'https://original.example.test', 'password' => 'original-account']))];
        $gateway = new InstalledProviderVncGateway($registry, $cipher);
        self::assertTrue($gateway->supported($source));
        self::assertSame('vnc-only-secret', $gateway->open($source)->password);
        $enabled = false;
        $this->expectException(PluginException::class);
        $gateway->open($source);
    }
}
