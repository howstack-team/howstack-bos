<?php

declare(strict_types=1);

namespace plugins\provider\magic_cloud\app\infrastructure;

use app\shared\plugin\contract\ScopedHttpClientInterface;
use app\shared\plugin\dto\{ConnectionContext, HttpRequest};
use app\shared\plugin\dto\provider\{LifecycleRequest, LifecycleResult};
use app\shared\plugin\exception\PluginException;

/** 魔方云自管资源：计费期限由核心管理；到期使用 due 暂停，续期后解除暂停。 */
final readonly class MagicCloudLifecycleGateway
{
    /** @param ScopedHttpClientInterface $http 已审核的固定路径模板 */
    public function __construct(private ScopedHttpClientInterface $http)
    {
    }

    /**
     * @param ConnectionContext $context 原账号
     * @param LifecycleRequest $request 原实例动作
     * @param bool $queryOnly 只读查询
     * @return LifecycleResult 明确结果；不根据HTTP受理推断完成
     */
    public function execute(ConnectionContext $context, LifecycleRequest $request, bool $queryOnly): LifecycleResult
    {
        if (!ctype_digit($request->externalId) || (int) $request->externalId < 1 || (int) $request->externalId > 2147483647) {
            throw new PluginException('供应实例标识无效', 'invalid_response');
        }
        $token = (new MagicCloudCatalogGateway($this->http))->authenticate($context->configuration->values);
        $row = $this->read($context, $request, $token);
        if ($row === null) {
            return new LifecycleResult('unknown');
        }
        $state = strtolower((string) ($row['status'] ?? ''));
        if (!in_array($state, ['on', 'off', 'running', 'stopped', 'suspend', 'suspended'], true)) {
            return new LifecycleResult('unknown');
        }
        // 此插件管理的是魔方云底层资源，未向第三方付费购买订阅，不能伪造上游续费交易。
        if ($request->action === 'renew') {
            return new LifecycleResult('succeeded', $request->targetExpiresAt);
        }
        $suspended = in_array($state, ['suspend', 'suspended'], true);
        $ready = in_array($state, ['on', 'off', 'running', 'stopped'], true);
        if (($request->action === 'suspend' && $suspended) || ($request->action === 'resume' && $ready)) {
            return new LifecycleResult('succeeded');
        }
        if ($queryOnly) {
            return new LifecycleResult('pending');
        }
        if (!$ready && !$suspended) {
            return new LifecycleResult('unknown');
        }
        $path = $request->action === 'suspend' ? '/v1/clouds/{id}/suspend' : '/v1/clouds/{id}/unsuspend';
        $this->call($context, new HttpRequest('POST', $path, ['access-token' => $token], json: $request->action === 'suspend' ? ['type' => 'due', 'free_gpu' => 0] : [], pathParameters: ['id' => (int) $request->externalId]));
        return new LifecycleResult('pending');
    }

    /**
     * @param ConnectionContext $context 原连接
     * @param LifecycleRequest $request 原身份
     * @param string $token 临时令牌
     * @return array<string,mixed>|null 身份精确一致的实例
     */
    private function read(ConnectionContext $context, LifecycleRequest $request, #[\SensitiveParameter] string $token): ?array
    {
        $raw = $this->call($context, new HttpRequest('GET', '/v1/clouds/{id}', ['access-token' => $token], pathParameters: ['id' => (int) $request->externalId]));
        $row = $raw['data'] ?? $raw;
        if (!is_array($row) || (string) ($row['id'] ?? '') !== $request->externalId || ($row['hostname'] ?? null) !== $request->hostname) {
            return null;
        }
        return $row;
    }

    /**
     * @param ConnectionContext $context 固定配置
     * @param HttpRequest $request 固定端点
     * @return array<string,mixed> 非成功均保持未知
     */
    private function call(ConnectionContext $context, HttpRequest $request): array
    {
        $response = $this->http->send($context, $request);
        $raw = json_decode($response->body, true);
        if ($response->status < 200 || $response->status >= 300 || !is_array($raw)) {
            throw new PluginException('供应操作结果尚未确认', 'result_unknown');
        }
        $code = $raw['code'] ?? null;
        if (($code !== null && !in_array($code, [0, 1, 200, '0', '1', '200'], true)) || !empty($raw['error'])) {
            throw new PluginException('供应操作结果尚未确认', 'result_unknown');
        }
        return $raw;
    }
}
