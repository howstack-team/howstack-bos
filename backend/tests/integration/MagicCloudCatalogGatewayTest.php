<?php

declare(strict_types=1);

namespace tests\integration;

use app\shared\plugin\exception\PluginException as BusinessException;
use app\modules\plugin\infrastructure\ScopedPluginHttpClient;
use plugins\provider\magic_cloud\app\infrastructure\MagicCloudCatalogGateway;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** 模拟上游 HTTP 验证协议；不能代替指定版本魔方云联调。 */
final class MagicCloudCatalogGatewayTest extends TestCase
{
    public function testCatalogUsesAccessTokenAndDoesNotExposeProviderSecrets(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"data":{"token":"fixture-token-1234567890"}}'),
            new Response(200, [], '{"data":[{"id":7,"name":"节点","area":3,"password":"do-not-return"}],"meta":{"total":25}}'),
        ]));
        $stack->push(Middleware::history($history));
        $gateway = new MagicCloudCatalogGateway($this->http(new Client(['handler' => $stack])));
        $result = $gateway->catalog($this->config(), 'nodes', ['page' => 1, 'per_page' => 10]);
        self::assertSame(25, $result['total']);
        self::assertSame('7', $result['data'][0]['external_id']);
        self::assertArrayNotHasKey('password', $result['data'][0]['metadata']);
        self::assertSame('/v1/login', $history[0]['request']->getUri()->getPath());
        self::assertSame('fixture-token-1234567890', $history[1]['request']->getHeaderLine('access-token'));
        self::assertSame('/v1/nodes', $history[1]['request']->getUri()->getPath());
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function testHtmlLoginResponseIsRejected(): void
    {
        $gateway = new MagicCloudCatalogGateway($this->http(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '<html>login page</html>')]))])));
        $this->expectException(BusinessException::class);
        $gateway->test($this->config());
    }

    public function testHttpFailureDoesNotEchoResponseBody(): void
    {
        $gateway = new MagicCloudCatalogGateway($this->http(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(401, [], 'password=secret')]))])));
        try { $gateway->test($this->config()); self::fail('应拒绝认证失败'); }
        catch (BusinessException $error) { self::assertSame('invalid_response', $error->reason); self::assertStringNotContainsString('secret', $error->getMessage()); }
    }

    private function http(Client $client): ScopedPluginHttpClient
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        return new ScopedPluginHttpClient($client, 'magic_cloud', $manifest['network']);
    }

    /** @return array{api_url:string,username:string,password:string} 非真实供应商配置 */
    private function config(): array { return ['api_url' => 'https://provider.example.test', 'username' => 'fixture', 'password' => 'fixture-password']; }
}
