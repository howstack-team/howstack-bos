<?php

declare(strict_types=1);

namespace plugins\provider\sample_provider;

use app\shared\plugin\contract\{ProviderAdapterInterface, ScopedHttpClientInterface};
use app\shared\plugin\dto\{CatalogPage, CatalogQuery, Configuration, ConnectionContext, ConnectionTestResult, HttpRequest, ProviderBinding};
use app\shared\plugin\exception\PluginException;

/** 仅用于 SDK 扩展测试，不安装到业务插件目录。 */
final readonly class ProviderAdapter implements ProviderAdapterInterface
{
    /** @param ScopedHttpClientInterface $http 宿主限定端点的测试客户端 */
    public function __construct(private ScopedHttpClientInterface $http) {}

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $values = $configuration->values;
        $values['endpoint'] = rtrim($values['endpoint'], '/');
        return new Configuration($values);
    }

    /** @inheritDoc */
    public function testConnection(ConnectionContext $context): ConnectionTestResult
    {
        $response = $this->http->send($context, new HttpRequest(
            'GET',
            '/health',
            ['X-Test-Key' => $context->configuration->values['api_key']],
        ));
        if ($response->status !== 200) {
            throw new PluginException('测试平台连接失败', 'external_failure');
        }
        return new ConnectionTestResult();
    }

    /** @inheritDoc */
    public function listCatalog(ConnectionContext $context, CatalogQuery $query): CatalogPage
    {
        if ($query->kind !== 'locations') {
            throw new PluginException('测试平台不支持该资源目录', 'capability_denied');
        }
        $response = $this->http->send($context, new HttpRequest(
            'GET',
            '/locations',
            ['X-Test-Key' => $context->configuration->values['api_key']],
            ['page' => $query->page, 'per_page' => $query->perPage],
        ));
        if ($response->status !== 200) {
            throw new PluginException('测试平台目录查询失败', 'external_failure');
        }
        $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        return new CatalogPage($result['data'], $result['total'], $query->page);
    }

    /** @inheritDoc */
    public function validateBinding(ProviderBinding $binding): void {}
}
