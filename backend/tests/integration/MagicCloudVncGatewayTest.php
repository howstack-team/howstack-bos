<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\plugin\infrastructure\ScopedPluginHttpClient;
use app\shared\plugin\dto\{Configuration, ConnectionContext};
use app\shared\plugin\dto\provider\{InstanceIdentity, VncConnection};
use app\shared\plugin\exception\PluginException;
use plugins\provider\magic_cloud\app\infrastructure\MagicCloudVncGateway;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** 真实插件及受限HTTP客户端，模拟用户提供的文档协议。 */
final class MagicCloudVncGatewayTest extends TestCase
{
    private function fixture(array &$history, array $vnc, string $hostname = 'bos-original'): array
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '"fixture-token-1234567890"'),
            new Response(200, [], json_encode(['data' => ['id' => 123, 'hostname' => $hostname]])),
            new Response(200, [], json_encode(['data' => $vnc])),
        ]));
        $stack->push(Middleware::history($history));
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/plugin.json'), true);
        $http = new ScopedPluginHttpClient(new Client(['handler' => $stack]), 'magic_cloud', $manifest['network']);
        $context = new ConnectionContext('magic_cloud', new Configuration(['api_url' => 'https://provider.example.test', 'username' => 'fixture', 'password' => 'api-secret', 'client_id' => 98]));
        return [new MagicCloudVncGateway($http), $context];
    }

    public function testDocumentedEndpointKeepsOnlyVncCredentialsAndRedactsSerialization(): void
    {
        $history = [];
        [$gateway, $context] = $this->fixture($history, ['vnc_url' => 'wss://vnc.example.test/cloud?token=vnc-token', 'vnc_pass' => 'vnc-secret',
            'password' => 'guest-system-password-never-returned', 'token' => 'other-token', 'vnc_url_https' => 'https://provider.example.test/v1/novnc?secret']);
        $result = $gateway->open($context, new InstanceIdentity('123', 'bos-original'));
        self::assertSame('vnc-secret', $result->password);
        self::assertSame('wss://vnc.example.test/cloud?token=vnc-token', $result->websocketUrl);
        self::assertSame('POST', $history[2]['request']->getMethod());
        self::assertSame('/v1/clouds/123/vnc', $history[2]['request']->getUri()->getPath());
        self::assertStringNotContainsString('secret', json_encode($result));
        self::assertStringNotContainsString('vnc-token', print_r($result, true));
        self::assertStringNotContainsString('guest-system-password', json_encode(get_object_vars($result)));
    }

    public function testForeignHostnameNeverIssuesVncTicket(): void
    {
        $history = [];
        [$gateway, $context] = $this->fixture($history, [], 'someone-else');
        try {
            $gateway->open($context, new InstanceIdentity('123', 'bos-original'));
            self::fail('必须核验原主机名');
        } catch (PluginException $error) {
            self::assertSame('external_identity_conflict', $error->reason);
        }
        self::assertCount(2, $history);
    }

    public function testUnsafeOrExternalHtmlUrlsAreRejected(): void
    {
        foreach (['ws://vnc.example.test/socket', 'https://provider.example.test/v1/novnc', 'javascript:alert(1)',
            'wss://user:secret@vnc.example.test/socket', 'wss://vnc.example.test/socket#secret', "wss://vnc.example.test/\nsecret", 'wss://vnc.example.test\\evil'] as $url) {
            try {
                new VncConnection($url, 'vnc-secret');
                self::fail('拒绝不安全地址');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
