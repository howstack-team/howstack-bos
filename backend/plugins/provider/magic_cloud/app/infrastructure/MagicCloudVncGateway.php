<?php

declare(strict_types=1);

namespace plugins\provider\magic_cloud\app\infrastructure;

use app\shared\plugin\contract\ScopedHttpClientInterface;
use app\shared\plugin\dto\{ConnectionContext, HttpRequest};
use app\shared\plugin\dto\provider\{InstanceIdentity, VncConnection};
use app\shared\plugin\exception\PluginException;

/** 使用文档中的POST实例VNC接口，只返回WSS和VNC密码，忽略系统密码与外部HTML页面。 */
final readonly class MagicCloudVncGateway
{
    /**
     * @param ScopedHttpClientInterface $http 固定端点和TLS保护
     */
    public function __construct(private ScopedHttpClientInterface $http)
    {
    }

    /**
     * @param ConnectionContext $context 原账号
     * @param InstanceIdentity $instance 原资源
     * @return VncConnection 经过检查的直接连接
     */
    public function open(ConnectionContext $context, InstanceIdentity $instance): VncConnection
    {
        if (!ctype_digit($instance->externalId) || (int) $instance->externalId < 1 || (int) $instance->externalId > 2147483647) {
            throw new PluginException('供应实例标识无效', 'invalid_response');
        }
        $headers = ['access-token' => (new MagicCloudCatalogGateway($this->http))->authenticate($context->configuration->values)];
        $parameters = ['id' => (int) $instance->externalId];
        $row = $this->call($context, new HttpRequest('GET', '/v1/clouds/{id}', $headers, pathParameters: $parameters));
        $row = $row['data'] ?? $row;
        if (!is_array($row) || (string) ($row['id'] ?? '') !== $instance->externalId || ($row['hostname'] ?? null) !== $instance->hostname) {
            throw new PluginException('供应实例身份不一致', 'external_identity_conflict');
        }
        $raw = $this->call($context, new HttpRequest('POST', '/v1/clouds/{id}/vnc', $headers, json: [], pathParameters: $parameters));
        $raw = $raw['data'] ?? $raw;
        if (!is_array($raw) || !is_string($raw['vnc_url'] ?? null) || !is_string($raw['vnc_pass'] ?? null)) {
            throw new PluginException('供应商未返回可用的VNC连接', 'vnc_unavailable');
        }
        try {
            return new VncConnection($raw['vnc_url'], $raw['vnc_pass']);
        } catch (\InvalidArgumentException) {
            throw new PluginException('供应商未返回有效的WSS地址，请检查VNC外部访问配置', 'vnc_unavailable');
        }
    }

    /**
     * @param ConnectionContext $context 原连接
     * @param HttpRequest $request 固定请求
     * @return array<string,mixed> 不泄露供应异常与凭据
     */
    private function call(ConnectionContext $context, HttpRequest $request): array
    {
        $response = $this->http->send($context, $request);
        $raw = json_decode($response->body, true);
        if ($response->status < 200 || $response->status >= 300 || !is_array($raw) || !empty($raw['error'])
            || (isset($raw['code']) && !in_array($raw['code'], [0, 1, 200, '0', '1', '200'], true))) {
            throw new PluginException('VNC连接暂不可用', 'vnc_unavailable');
        }
        return $raw;
    }
}
