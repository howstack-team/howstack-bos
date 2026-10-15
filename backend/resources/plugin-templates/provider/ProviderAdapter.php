<?php

declare(strict_types=1);

namespace plugins\provider\__PLUGIN_ID__;

use app\shared\plugin\contract\{ProviderAdapterInterface, ScopedHttpClientInterface};
use app\shared\plugin\dto\{Configuration, ConnectionContext, ConnectionTestResult, CatalogQuery, CatalogPage, ProviderBinding};
use app\shared\plugin\exception\PluginException;

/** 供应 SDK v1 示例；完成平台协议及契约测试后才允许审核启用。 */
final readonly class ProviderAdapter implements ProviderAdapterInterface
{
    /** @param ScopedHttpClientInterface $http 宿主受限网络服务 */
    public function __construct(private ScopedHttpClientInterface $http) {}

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        throw new PluginException('请先实现平台配置校验');
    }

    /** @inheritDoc */
    public function testConnection(ConnectionContext $context): ConnectionTestResult
    {
        throw new PluginException('请先实现连接认证', 'not_implemented');
    }

    /** @inheritDoc */
    public function listCatalog(ConnectionContext $context, CatalogQuery $query): CatalogPage
    {
        throw new PluginException('请先实现资源目录', 'not_implemented');
    }

    /** @inheritDoc */
    public function validateBinding(ProviderBinding $binding): void
    {
        throw new PluginException('请先实现配置对应校验', 'not_implemented');
    }
}
