<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\plugin\infrastructure\ScopedPluginHttpClient;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\ConnectionContext;
use app\shared\plugin\dto\HttpResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** 验证实际 Guzzle 编码和选项，不访问真实外部平台。 */
final class PluginHttpClientTest extends TestCase
{
    public function testGetAndJsonPostUseDefaultsAndKeepResponsesSeparate(): void
    {
        $history = [];
        $http = new HttpClient($this->client($history, [
            new Response(200, ['X-Request-Id' => 'external-first'], '{"items":[1,2]}'),
            new Response(202, [], '{"accepted":true}'),
        ]));
        $first = $http->get('https://api.example.test/list', ['name' => '云服务器', 'page' => 2], ['Authorization' => 'Bearer first']);
        $second = $http->post('https://api.example.test/send', ['message' => ['text' => '通知']], ['Authorization' => 'Bearer second']);
        self::assertSame([1, 2], $first->json()['items']);
        self::assertSame(['external-first'], $first->headers['X-Request-Id']);
        self::assertTrue($second->isSuccessful());
        self::assertSame(202, $second->status);
        self::assertSame(['message' => ['text' => '通知']], json_decode((string) $history[1]['request']->getBody(), true));
        self::assertSame('application/json', $history[1]['request']->getHeaderLine('Content-Type'));
        self::assertSame('Bearer first', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer second', $history[1]['request']->getHeaderLine('Authorization'));
        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['name' => '云服务器', 'page' => '2'], $query);
        foreach ($history as $entry) {
            self::assertTrue($entry['options']['verify']);
            self::assertFalse($entry['options']['allow_redirects']);
            self::assertFalse($entry['options']['http_errors']);
            self::assertSame(10, $entry['options']['timeout']);
            self::assertSame(4, $entry['options']['connect_timeout']);
        }
    }

    public function testFormAndRawRequestsAndEmbeddedQueryArePreserved(): void
    {
        $history = [];
        $http = new HttpClient($this->client($history, [new Response(200), new Response(200), new Response(200)]));
        $http->postForm('https://api.example.test/form', ['name' => '皓量', 'enabled' => 1]);
        $http->request('PATCH', 'https://api.example.test/item', [
            'body' => 'signed-body',
            'headers' => ['Content-Type' => 'text/plain'],
            'timeout' => 2,
        ]);
        $http->get('https://api.example.test/list?region=hk');
        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertSame(['name' => '皓量', 'enabled' => '1'], $form);
        self::assertStringStartsWith('application/x-www-form-urlencoded', $history[0]['request']->getHeaderLine('Content-Type'));
        self::assertSame('signed-body', (string) $history[1]['request']->getBody());
        self::assertSame('PATCH', $history[1]['request']->getMethod());
        self::assertSame(2, $history[1]['options']['timeout']);
        self::assertSame(2, $history[1]['options']['connect_timeout']);
        self::assertSame('region=hk', $history[2]['request']->getUri()->getQuery());
    }

    public function testHttpErrorsAndRedirectsAreReturnedWithoutRetry(): void
    {
        $history = [];
        $http = new HttpClient($this->client($history, [
            new Response(302, ['Location' => 'https://unapproved.example.test/']),
            new Response(422, [], '{"error":"invalid"}'),
            new Response(503),
        ]));
        foreach ([302, 422, 503] as $status) {
            $result = $http->post('https://api.example.test/send');
            self::assertSame($status, $result->status);
            self::assertFalse($result->isSuccessful());
        }
        self::assertCount(3, $history);
    }

    public function testPutAndDeleteShortcutsUseCorrectVerbsAndPayloads(): void
    {
        $history = [];
        $http = new HttpClient($this->client($history, [new Response(200), new Response(204), new Response(204)]));
        $http->put('https://api.example.test/items/1', ['name' => '云服务器'], ['Authorization' => 'Bearer test']);
        $http->delete('https://api.example.test/items/1', ['force' => 1]);
        $http->request('DELETE', 'https://api.example.test/items', ['json' => ['ids' => [1, 2]]]);
        self::assertSame('PUT', $history[0]['request']->getMethod());
        self::assertSame(['name' => '云服务器'], json_decode((string) $history[0]['request']->getBody(), true));
        self::assertSame('Bearer test', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('DELETE', $history[1]['request']->getMethod());
        self::assertSame('force=1', $history[1]['request']->getUri()->getQuery());
        self::assertSame('', (string) $history[1]['request']->getBody());
        self::assertSame(['ids' => [1, 2]], json_decode((string) $history[2]['request']->getBody(), true));
    }

    public function testNetworkFailureKeepsCauseAndDoesNotLeakOrRetry(): void
    {
        $history = [];
        $cause = new ConnectException('secret-platform-detail', new Request('POST', 'https://api.example.test/send'));
        $http = new HttpClient($this->client($history, [$cause, new Response(202)]));
        try {
            $http->post('https://api.example.test/send');
            self::fail('断线应报告请求未完成');
        } catch (PluginException $error) {
            self::assertSame('network_error', $error->reason);
            self::assertSame($cause, $error->getPrevious());
            self::assertStringNotContainsString('secret-platform-detail', $error->getMessage());
        }
        self::assertCount(1, $history);
    }

    public function testOversizedResponseIsRejectedAndStreamIsClosed(): void
    {
        $history = [];
        $response = new Response(200, [], str_repeat('x', 2097153));
        $http = new HttpClient($this->client($history, [$response]));
        try {
            $http->get('https://api.example.test/large');
            self::fail('过大的响应应拒绝');
        } catch (PluginException $error) {
            self::assertSame('invalid_response', $error->reason);
        }
        self::assertFalse($response->getBody()->isReadable());
    }

    public function testInvalidOptionsAndUrlsNeverReachNetwork(): void
    {
        $history = [];
        $http = new HttpClient($this->client($history, []));
        $invalid = [
            ['file:///etc/passwd', []],
            ['https://user:password@api.example.test/', []],
            ['https://api.example.test/#fragment', []],
            ['https://api.example.test/', ['verify' => false]],
            ['https://api.example.test/', ['allow_redirects' => true]],
            ['https://api.example.test/', ['timeout' => 0]],
            ['https://api.example.test/', ['timeout' => null]],
            ['https://api.example.test/', ['connect_timeout' => INF]],
            ['https://api.example.test/', ['timeout' => 31]],
            ['https://api.example.test/', ['headers' => ['Authorization' => "key\r\nInjected: yes"]]],
            ['https://api.example.test/', ['headers' => ['Host' => 'other.example.test']]],
            ['https://api.example.test/', ['json' => [], 'form_params' => []]],
            ['https://api.example.test/', ['body' => []]],
            ['https://api.example.test/', ['body' => null]],
        ];
        foreach ($invalid as [$url, $options]) {
            try {
                $http->request('POST', $url, $options);
                self::fail('无效请求应拒绝');
            } catch (PluginException $error) {
                self::assertSame('invalid_request', $error->reason);
            }
        }
        self::assertCount(0, $history);
    }

    public function testJsonParsingDoesNotExposeInvalidResponseBody(): void
    {
        foreach (['secret-invalid-json', 'null', '"text"', '42'] as $body) {
            try {
                (new HttpResult(200, $body))->json();
                self::fail('非 JSON 数组或对象应拒绝');
            } catch (PluginException $error) {
                self::assertSame('invalid_response', $error->reason);
                self::assertStringNotContainsString('secret-invalid-json', $error->getMessage());
            }
        }
    }

    public function testScopedShortcutsKeepConnectionAndEndpointChecks(): void
    {
        $history = [];
        $http = new ScopedPluginHttpClient($this->client($history, [
            new Response(200, [], '{}'), new Response(202), new Response(200), new Response(204),
        ]), 'example', [
            'base_url_field' => 'endpoint',
            'endpoints' => [
                ['method' => 'GET', 'path' => '/list'], ['method' => 'POST', 'path' => '/send'],
                ['method' => 'PUT', 'path' => '/item'], ['method' => 'DELETE', 'path' => '/item'],
            ],
        ]);
        $context = new ConnectionContext('example', new Configuration(['endpoint' => 'https://api.example.test']));
        self::assertSame([], $http->get($context, '/list', ['page' => 2])->json());
        self::assertSame(202, $http->post($context, '/send', ['text' => '通知'])->status);
        self::assertSame(200, $http->put($context, '/item', ['name' => '云服务器'])->status);
        self::assertSame(204, $http->delete($context, '/item', ['force' => 1])->status);
        try {
            $http->post($context, '/unapproved');
            self::fail('未审核端点应拒绝');
        } catch (PluginException $error) {
            self::assertSame('capability_denied', $error->reason);
        }
        self::assertCount(4, $history);
        self::assertSame('https://api.example.test/send', (string) $history[1]['request']->getUri());
        self::assertSame('PUT', $history[2]['request']->getMethod());
        self::assertSame('DELETE', $history[3]['request']->getMethod());
    }

    /**
     * @param array $history 捕获实际 Guzzle 请求及选项
     * @param array $responses Mock 响应或异常
     * @return Client 隔离的底层客户端
     */
    private function client(array &$history, array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new Client(['handler' => $stack]);
    }
}
