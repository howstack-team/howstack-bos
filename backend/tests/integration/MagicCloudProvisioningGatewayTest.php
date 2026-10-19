<?php

declare(strict_types=1);
namespace tests\integration;
use app\modules\plugin\infrastructure\ScopedPluginHttpClient;
use app\shared\plugin\dto\{ConnectionContext, Configuration};
use app\shared\plugin\dto\provider\ProvisionRequest;
use plugins\provider\magic_cloud\app\infrastructure\MagicCloudProvisioningGateway;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
/** 使用真实插件适配器和受限 HTTP，外部响应替身不代表魔方云实机验收。 */
final class MagicCloudProvisioningGatewayTest extends TestCase
{
    private function gateway(array $responses, array &$history): MagicCloudProvisioningGateway
    {
        $stack = HandlerStack::create(new MockHandler($responses)); $stack->push(Middleware::history($history));
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/plugin.json'), true);
        return new MagicCloudProvisioningGateway(new ScopedPluginHttpClient(new Client(['handler' => $stack]), 'magic_cloud', $manifest['network']));
    }
    private function context(): ConnectionContext { return new ConnectionContext('magic_cloud', new Configuration(['api_url' => 'https://provider.example.test', 'username' => 'fixture', 'password' => 'api-secret', 'client_id' => 98])); }
    private function request(?string $id = null, array $parameters = []): ProvisionRequest
    {
        return new ProvisionRequest('bos-fixed-request', ['flavor' => ['cpu' => 4, 'memory_mib' => 8192], 'zone' => ['external_id' => '11'], 'flavor_group' => ['external_id' => '12'],
            'image' => ['external_id' => '13'], 'line' => ['external_id' => '14'], 'system_disk' => ['type' => ['external_id' => '15'], 'size_gib' => 40],
            'data_disks' => [['type' => ['external_id' => '16'], 'size_gib' => 100]], 'network' => ['egress_mbps' => 20, 'ingress_mbps' => 100, 'ipv4_count' => 2]], $parameters, 'Initial@123456', $id);
    }
    public function testCreateUsesOneInstanceFixedHostAndMappedIdentifiers(): void
    {
        $history = [];
        $gateway = $this->gateway([new Response(200, [], '"fixture-token-1234567890"'), new Response(200, [], '{"code":200,"data":{"id":123}}')], $history);
        $result = $gateway->create($this->context(), $this->request());
        self::assertSame('pending', $result->status); self::assertSame('123', $result->externalId);
        $body = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame(1, $body['num']); self::assertSame('bos-fixed-request', $body['hostname']); self::assertSame(98, $body['client']);
        self::assertSame('13', $body['os']); self::assertSame('16', $body['other_data_disk'][0]['store']);
        self::assertSame('Initial@123456', $body['rootpass']); self::assertTrue($history[1]['options']['verify']);
        self::assertStringNotContainsString('Initial@123456', json_encode($this->request()));
        self::assertStringNotContainsString('Initial@123456', print_r($this->request(), true));
    }
    public function testQueryReconcilesByExactHostAndIgnoresOtherCustomers(): void
    {
        $history = [];
        $gateway = $this->gateway([new Response(200, [], '{"token":"fixture-token-1234567890"}'), new Response(200, [], '{"data":[{"id":9,"hostname":"another","status":"on"},{"id":123,"hostname":"bos-fixed-request","status":"off","mainip":"192.0.2.8","ips":["192.0.2.9"]}]}')], $history);
        $result = $gateway->query($this->context(), $this->request());
        self::assertSame('active', $result->status); self::assertSame('123', $result->externalId); self::assertSame(['192.0.2.8', '192.0.2.9'], $result->addresses);
        self::assertSame('GET', $history[1]['request']->getMethod());
    }
    public function testMissingResultAndAmbiguousHostnameNeverMeanSafeToRecreate(): void
    {
        foreach (['[]', '[{"id":1,"hostname":"bos-fixed-request"},{"id":2,"hostname":"bos-fixed-request"}]'] as $rows) {
            $history = []; $gateway = $this->gateway([new Response(200, [], '{"token":"fixture-token-1234567890"}'), new Response(200, [], '{"data":' . $rows . '}')], $history);
            self::assertSame('unknown', $gateway->query($this->context(), $this->request())->status);
        }
    }
    public function testReadyPowerStateWaitsForPurchasedIpAddresses(): void
    {
        $history = [];
        $gateway = $this->gateway([new Response(200, [], '{"token":"fixture-token-1234567890"}'), new Response(200, [], '{"data":[{"id":123,"hostname":"bos-fixed-request","status":"on","mainip":"192.0.2.8"}]}')], $history);
        self::assertSame('pending', $gateway->query($this->context(), $this->request())->status);
    }
    public function testProtectedSupplierParametersCannotOverrideIdentityOrPassword(): void
    {
        $history = []; $gateway = $this->gateway([], $history);
        self::assertSame('failed', $gateway->create($this->context(), $this->request(parameters: ['hostname' => 'other', 'rootpass' => 'override']))->status);
        self::assertCount(0, $history);
    }
}
