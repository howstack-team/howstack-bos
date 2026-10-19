<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\plugin\infrastructure\ScopedPluginHttpClient;
use app\shared\plugin\dto\{Configuration, ConnectionContext};
use app\shared\plugin\dto\provider\InstanceOperationRequest;
use plugins\provider\magic_cloud\app\infrastructure\MagicCloudInstanceOperationsGateway;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** 使用真实适配器及受限网络客户端，响应按用户提供的离线文档模拟。 */
final class MagicCloudInstanceOperationsTest extends TestCase
{
    private function runOperation(InstanceOperationRequest $request, bool $query, array $responses, array &$history): \app\shared\plugin\dto\provider\LifecycleResult
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '"fixture-token-1234567890"'),
            new Response(200, [], '{"data":{"id":123,"hostname":"bos-original"}}'),
            ...array_map(static fn (array $row) => new Response(200, [], json_encode($row)), $responses),
        ]));
        $stack->push(Middleware::history($history));
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/provider/magic_cloud/plugin.json'), true);
        $http = new ScopedPluginHttpClient(new Client(['handler' => $stack]), 'magic_cloud', $manifest['network']);
        $context = new ConnectionContext('magic_cloud', new Configuration(['api_url' => 'https://provider.example.test', 'username' => 'fixture', 'password' => 'secret', 'client_id' => 98]));
        return (new MagicCloudInstanceOperationsGateway($http))->execute($context, $request, $query);
    }

    public function testAllFiveDocumentedPathsAndReinstallNeverFormatsDataDisks(): void
    {
        foreach (['power_on' => ['POST', 'on'], 'power_off' => ['POST', 'off'], 'reboot' => ['POST', 'reboot'], 'reinstall' => ['PUT', 'reinstall'], 'reset_password' => ['PUT', 'password']] as $action => [$method, $suffix]) {
            $history = [];
            $request = new InstanceOperationRequest('operation-fixed-key', '123', 'bos-original', $action,
                ['image' => ['external_id' => '99', 'default_port' => 22]], 'Test@123456');
            $result = $this->runOperation($request, false, [['code' => 200, 'taskid' => 456, 'password' => 'ignored-provider-secret']], $history);
            self::assertSame('pending', $result->status);
            self::assertSame('456', $result->providerTaskId);
            self::assertSame($method, $history[2]['request']->getMethod());
            self::assertSame('/v1/clouds/123/' . $suffix, $history[2]['request']->getUri()->getPath());
            if ($action === 'reinstall') {
                $body = json_decode((string) $history[2]['request']->getBody(), true);
                self::assertSame(0, $body['format_data_disk']);
                self::assertSame(99, $body['os']);
                self::assertArrayNotHasKey('system_disk_size', $body);
            }
            self::assertStringNotContainsString('Test@123456', json_encode($request));
            self::assertStringNotContainsString('Test@123456', print_r($request, true));
        }
    }

    public function testTaskSuccessRequiresMatchingTaskAndCloudAndNeverReissuesMutation(): void
    {
        foreach (['reboot', 'reinstall', 'reset_password'] as $action) {
            $history = [];
            $request = new InstanceOperationRequest('operation-key', '123', 'bos-original', $action, providerTaskId: '456');
            self::assertSame('succeeded', $this->runOperation($request, true, [
                ['id' => 456, 'hostid' => 123, 'status' => 2, 'data' => ['dir' => '/not-used']], ['status' => 'on'],
            ], $history)->status);
            self::assertSame(['POST', 'GET', 'GET', 'GET'], array_map(static fn (array $call): string => $call['request']->getMethod(), $history));
            self::assertSame('/v1/tasks/456', $history[2]['request']->getUri()->getPath());
        }
        foreach ([['id' => 999, 'hostid' => 123, 'status' => 2], ['id' => 456, 'hostid' => 124, 'status' => 2]] as $bad) {
            $history = [];
            self::assertSame('unknown', $this->runOperation(new InstanceOperationRequest('operation-key', '123', 'bos-original', 'reboot', providerTaskId: '456'), true, [$bad], $history)->status);
            self::assertCount(3, $history);
        }
    }

    public function testTaskStatesAndMissingReceiptRemainHonest(): void
    {
        foreach ([0 => 'pending', 1 => 'pending', 3 => 'failed', 4 => 'failed', 5 => 'failed', 9 => 'unknown'] as $status => $expected) {
            $history = [];
            self::assertSame($expected, $this->runOperation(new InstanceOperationRequest('key', '123', 'bos-original', 'reboot', providerTaskId: '456'), true,
                [['id' => 456, 'hostid' => 123, 'status' => $status]], $history)->status);
        }
        $history = [];
        self::assertSame('unknown', $this->runOperation(new InstanceOperationRequest('key', '123', 'bos-original', 'reboot'), true, [], $history)->status);
        self::assertCount(2, $history);
    }

    public function testPowerActionNeedsConfirmedTargetPowerState(): void
    {
        foreach (['on' => 'pending', 'off' => 'succeeded'] as $state => $expected) {
            $history = [];
            self::assertSame($expected, $this->runOperation(new InstanceOperationRequest('key', '123', 'bos-original', 'power_off', providerTaskId: '456'), true,
                [['id' => 456, 'hostid' => 123, 'status' => 2], ['status' => $state]], $history)->status);
        }
    }
}
