<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\plugin\infrastructure\ScopedPluginHttpClient;
use app\shared\plugin\dto\{Configuration, ConnectionContext, HttpRequest};
use app\shared\plugin\dto\provider\LifecycleRequest;
use app\shared\plugin\exception\PluginException;
use plugins\provider\magic_cloud\app\infrastructure\MagicCloudLifecycleGateway;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** 真插件和受限HTTP配合模拟协议；不连接或操作真实资源。 */
final class MagicCloudLifecycleGatewayTest extends TestCase
{
    private function fixture(array $responses, array &$history): array
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/plugin.json'), true);
        $http = new ScopedPluginHttpClient(new Client(['handler' => $stack]), 'magic_cloud', $manifest['network']);
        $context = new ConnectionContext('magic_cloud', new Configuration(['api_url' => 'https://provider.example.test', 'username' => 'fixture', 'password' => 'secret', 'client_id' => 98]));
        return [new MagicCloudLifecycleGateway($http), $context, $http];
    }

    private function responses(string $state): array
    {
        return [new Response(200, [], '"fixture-token-1234567890"'), new Response(200, [], json_encode(['data' => ['id' => 123, 'hostname' => 'bos-origin', 'status' => $state]]))];
    }

    public function testOwnedResourceRenewalDoesNotInventUpstreamPurchase(): void
    {
        $history = [];
        [$gateway, $context] = $this->fixture($this->responses('on'), $history);
        $result = $gateway->execute($context, new LifecycleRequest('renew-1', '123', 'bos-origin', 'renew', '2027-01-31 12:00:00', months: 1), false);
        self::assertSame('succeeded', $result->status);
        self::assertSame('2027-01-31 12:00:00', $result->confirmedExpiresAt);
        self::assertCount(2, $history);
        self::assertSame('GET', $history[1]['request']->getMethod());
        self::assertSame('/v1/clouds/123', $history[1]['request']->getUri()->getPath());
    }

    public function testSuspendIsAcceptedThenOnlyQueriedUntilConfirmed(): void
    {
        $history = [];
        [$gateway, $context] = $this->fixture([...$this->responses('on'), new Response(200, [], '{"code":200,"taskid":1}')], $history);
        $request = new LifecycleRequest('suspend-1', '123', 'bos-origin', 'suspend', '2026-01-31 12:00:00');
        self::assertSame('pending', $gateway->execute($context, $request, false)->status);
        self::assertSame('/v1/clouds/123/suspend', $history[2]['request']->getUri()->getPath());
        self::assertSame(['type' => 'due', 'free_gpu' => 0], json_decode((string) $history[2]['request']->getBody(), true));
        $history = [];
        [$gateway, $context] = $this->fixture($this->responses('suspended'), $history);
        self::assertSame('succeeded', $gateway->execute($context, $request, true)->status);
        self::assertCount(2, $history);
    }

    public function testResumeAndHostnameMismatchNeverChangeOtherInstances(): void
    {
        $history = [];
        [$gateway, $context] = $this->fixture([...$this->responses('suspended'), new Response(200, [], '{"code":200}')], $history);
        $request = new LifecycleRequest('resume-1', '123', 'bos-origin', 'resume', '2027-01-31 12:00:00');
        self::assertSame('pending', $gateway->execute($context, $request, false)->status);
        self::assertSame('/v1/clouds/123/unsuspend', $history[2]['request']->getUri()->getPath());
        $history = [];
        [$gateway, $context] = $this->fixture($this->responses('on'), $history);
        self::assertSame('unknown', $gateway->execute($context, new LifecycleRequest('other', '123', 'someone-else', 'renew', '2027-01-31 12:00:00', months: 1), false)->status);
        self::assertCount(2, $history);
    }

    public function testApprovedPathParametersCannotEscapeNumericSegment(): void
    {
        foreach ([['id' => '../login'], ['id' => 0], ['other' => 123], [], ['id' => 2147483648]] as $parameters) {
            $history = [];
            [, $context, $http] = $this->fixture([], $history);
            try {
                $http->send($context, new HttpRequest('GET', '/v1/clouds/{id}', pathParameters: $parameters));
                self::fail('路径参数必须按受限模板验证');
            } catch (PluginException $error) {
                self::assertSame('capability_denied', $error->reason);
            }
            self::assertSame([], $history);
        }
    }
}
