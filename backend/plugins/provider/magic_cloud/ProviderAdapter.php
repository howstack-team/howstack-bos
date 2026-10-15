<?php

declare(strict_types=1);

namespace plugins\provider\magic_cloud;

use app\shared\plugin\contract\{ProviderAdapterInterface, ScopedHttpClientInterface};
use app\shared\plugin\dto\{CatalogPage, CatalogQuery, Configuration, ConnectionContext, ConnectionTestResult, ProviderBinding};
use app\shared\plugin\exception\PluginException;
use plugins\provider\magic_cloud\app\infrastructure\MagicCloudCatalogGateway;

/** 魔方云供应 SDK v1；只读目录，未开放资源生产能力。 */
final readonly class ProviderAdapter implements ProviderAdapterInterface
{
    private MagicCloudCatalogGateway $gateway;

    /**
     * 只注入受限网络门面，构造阶段没有外部副作用。
     * @param ScopedHttpClientInterface $http 本插件固定端点的 HTTP 能力
     */
    public function __construct(ScopedHttpClientInterface $http)
    {
        $this->gateway = new MagicCloudCatalogGateway($http);
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $values = $configuration->values;
        $values['api_url'] = rtrim((string) $values['api_url'], '/');
        $url = parse_url($values['api_url']);
        if (!is_array($url) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new PluginException('请填写魔方云系统的访问地址，例如 https://cloud.example.com，不包含登录信息或查询参数');
        }
        if (str_ends_with($url['path'] ?? '', '/v1')) {
            throw new PluginException('请填写魔方云系统的访问地址，去掉末尾的 /v1');
        }
        return new Configuration($values);
    }

    /** @inheritDoc */
    public function testConnection(ConnectionContext $context): ConnectionTestResult
    {
        $this->gateway->test($context->configuration->values);
        return new ConnectionTestResult();
    }

    /** @inheritDoc */
    public function listCatalog(ConnectionContext $context, CatalogQuery $query): CatalogPage
    {
        $page = $this->gateway->catalog($context->configuration->values, $query->kind, ['page' => $query->page, 'per_page' => $query->perPage]);
        return new CatalogPage($page['data'], $page['total'], $page['page']);
    }

    /** @inheritDoc */
    public function validateBinding(ProviderBinding $binding): void
    {
        $resources = $binding->resources;
        $mapping = $binding->mapping;
        $catalog = $binding->catalog;
        if (($resources['protections'] ?? []) !== []) {
            throw new PluginException('魔方云插件尚未声明防护能力，不能发布包含防护的方案', 'invalid_binding');
        }
        $kinds = ['zones' => 'areas', 'flavor_groups' => 'nodes', 'images' => 'images', 'disk_types' => 'stores', 'lines' => 'ip_groups'];
        foreach ($kinds as $kind => $catalogKind) {
            foreach ($resources[$kind] ?? [] as $row) {
                $entry = $catalog[$catalogKind][$mapping[$kind][$row['id']]];
                if (isset($entry['metadata']['enable']) && in_array($entry['metadata']['enable'], [0, '0', false], true)) {
                    throw new PluginException($row['name'] . '对应的魔方云资源已停用', 'invalid_binding');
                }
            }
        }
        foreach (['flavors', 'images', 'disk_types', 'lines'] as $kind) {
            foreach ($resources[$kind] ?? [] as $row) {
                $mappedKind = $kind === 'flavors' ? 'flavor_groups' : $kind;
                $local = $kind === 'flavors' ? $row['group_id'] : $row['id'];
                $metadata = $catalog[$kinds[$mappedKind]][$mapping[$mappedKind][$local]]['metadata'];
                $area = $mapping['zones'][$row['zone_id']];
                if (isset($metadata['area']) && (string) $metadata['area'] !== $area) {
                    throw new PluginException($row['name'] . '的外部资源与可用区不匹配', 'invalid_binding');
                }
            }
        }
    }
}
