<?php

declare(strict_types=1);

namespace plugins\provider\magic_cloud\app\infrastructure;

use app\shared\plugin\contract\ScopedHttpClientInterface;
use app\shared\plugin\dto\{ConnectionContext, HttpRequest};
use app\shared\plugin\dto\provider\{InstanceOperationRequest, LifecycleResult};
use app\shared\plugin\exception\PluginException;

/** 对照魔方云离线 API 文档；任务详情必须同时匹配任务 ID 和实例 ID。 */
final readonly class MagicCloudInstanceOperationsGateway
{
    /**
     * @param ScopedHttpClientInterface $http 受限HTTP
     */
    public function __construct(private ScopedHttpClientInterface $http)
    {
    }

    /**
     * @param ConnectionContext $context 原连接
     * @param InstanceOperationRequest $request 固定请求
     * @param bool $queryOnly 仅查单
     * @return LifecycleResult 明确任务结果
     */
    public function execute(ConnectionContext $context, InstanceOperationRequest $request, bool $queryOnly): LifecycleResult
    {
        $id = $this->id($request->externalId);
        $token = (new MagicCloudCatalogGateway($this->http))->authenticate($context->configuration->values);
        $headers = ['access-token' => $token];
        $row = $this->call($context, new HttpRequest('GET', '/v1/clouds/{id}', $headers, pathParameters: ['id' => $id]));
        $row = $row['data'] ?? $row;
        if ((string) ($row['id'] ?? '') !== $request->externalId || ($row['hostname'] ?? null) !== $request->hostname) {
            return new LifecycleResult('unknown');
        }
        if ($queryOnly) {
            if ($request->providerTaskId === null) {
                // 回执丢失不能按任务列表“最近一条”猜测，更不能再次发送破坏性操作。
                return new LifecycleResult('unknown');
            }
            $task = $this->call($context, new HttpRequest('GET', '/v1/tasks/{id}', $headers, pathParameters: ['id' => $this->id($request->providerTaskId)]));
            // 任务详情本身含有data(导出磁盘信息)，不能盲目解包该字段。
            if (!isset($task['id']) && isset($task['data']['id'])) {
                $task = $task['data'];
            }
            if ((string) ($task['id'] ?? '') !== $request->providerTaskId || (string) ($task['hostid'] ?? '') !== $request->externalId) {
                return new LifecycleResult('unknown');
            }
            $status = $task['status'] ?? null;
            if (in_array($status, [0, 1, '0', '1'], true)) {
                return new LifecycleResult('pending', providerTaskId: $request->providerTaskId);
            }
            if (in_array($status, [3, 4, 5, '3', '4', '5'], true)) {
                return new LifecycleResult('failed', providerTaskId: $request->providerTaskId);
            }
            if (!in_array($status, [2, '2'], true)) {
                return new LifecycleResult('unknown');
            }
            $power = $this->call($context, new HttpRequest('GET', '/v1/clouds/{id}/status', $headers, pathParameters: ['id' => $id]));
            $power = $power['data']['status'] ?? $power['status'] ?? null;
            if (($request->action === 'power_on' && $power !== 'on') || ($request->action === 'power_off' && $power !== 'off')) {
                return new LifecycleResult('pending', providerTaskId: $request->providerTaskId);
            }
            return new LifecycleResult('succeeded', providerTaskId: $request->providerTaskId, powerStatus: in_array($power, ['on', 'off'], true) ? $power : null);
        }
        $paths = ['power_on' => 'on', 'power_off' => 'off', 'reboot' => 'reboot', 'reinstall' => 'reinstall', 'reset_password' => 'password'];
        $json = [];
        if (in_array($request->action, ['reinstall', 'reset_password'], true)) {
            if ($request->password === null) {
                throw new PluginException('运维密码不可用', 'credential_unavailable');
            }
            $json = ['password' => $request->password, 'password_type' => 0];
        }
        if ($request->action === 'reinstall') {
            $json += ['os' => $this->id((string) ($request->options['image']['external_id'] ?? '')),
                'port' => (int) $request->options['image']['default_port'], 'format_data_disk' => 0];
        }
        $raw = $this->call($context, new HttpRequest($json ? 'PUT' : 'POST', '/v1/clouds/{id}/' . $paths[$request->action], $headers, json: $json, pathParameters: ['id' => $id]));
        $taskId = $raw['taskid'] ?? $raw['data']['taskid'] ?? null;
        if ((!is_int($taskId) && !is_string($taskId)) || !ctype_digit((string) $taskId) || (int) $taskId < 1) {
            return new LifecycleResult('unknown');
        }
        return new LifecycleResult('pending', providerTaskId: (string) $this->id((string) $taskId));
    }

    /**
     * @param string $value 供应标识
     * @return int 受限路径整数
     */
    private function id(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 2147483647) {
            throw new PluginException('供应标识无效', 'invalid_response');
        }
        return (int) $value;
    }

    /**
     * @param ConnectionContext $context 原连接
     * @param HttpRequest $request 固定请求
     * @return array<string,mixed> 不泄露供应原文
     */
    private function call(ConnectionContext $context, HttpRequest $request): array
    {
        $response = $this->http->send($context, $request);
        $raw = json_decode($response->body, true);
        if ($response->status < 200 || $response->status >= 300 || !is_array($raw) || !empty($raw['error'])
            || (isset($raw['code']) && !in_array($raw['code'], [0, 1, 200, '0', '1', '200'], true))) {
            throw new PluginException('供应操作结果未知', 'result_unknown');
        }
        return $raw;
    }
}
