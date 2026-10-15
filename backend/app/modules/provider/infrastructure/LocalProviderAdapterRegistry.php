<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure;

use app\exception\BusinessException;
use app\modules\plugin\contract\{PluginInstallationRepositoryInterface, PluginPackageRegistryInterface};
use app\modules\plugin\infrastructure\{LocalPluginPackageRegistry, ScopedPluginHttpClient};
use app\modules\plugin\infrastructure\PluginDependencyLoader;
use app\modules\provider\contract\{ProviderAdapterInterface, ProviderAdapterRegistryInterface};
use app\shared\http\ApiErrorCode;
use GuzzleHttp\ClientInterface;
use Throwable;

/** 固定入口加载；包错误隔离，安装状态每次查询，不在常驻进程缓存连接秘密。 */
final readonly class LocalProviderAdapterRegistry implements ProviderAdapterRegistryInterface
{
    private PluginPackageRegistryInterface $packages;

    /**
     * @param string $pluginsPath 分类插件根目录
     * @param string $coreVersion 宿主版本
     * @param ClientInterface $http 宿主 HTTP 客户端
     * @param PluginInstallationRepositoryInterface $installations 安装状态
     * @param PluginPackageRegistryInterface|null $packages 已校验包注册器
     */
    public function __construct(
        string $pluginsPath,
        string $coreVersion,
        private ClientInterface $http,
        private PluginInstallationRepositoryInterface $installations,
        ?PluginPackageRegistryInterface $packages = null,
    ) {
        $this->packages = $packages ?? new LocalPluginPackageRegistry($pluginsPath, $coreVersion);
    }

    /** @inheritDoc */
    public function definitions(bool $includeDraining = false): array
    {
        $records = $this->installations->all();
        $result = [];
        foreach ($this->packages->packages() as $id => $package) {
            if ($this->available($package, $records[$id] ?? [], $includeDraining ? ['enabled', 'draining'] : ['enabled'])) {
                $result[] = $this->describe($package, $records[$id]['state'] === 'enabled');
            }
        }
        return $result;
    }

    /** @inheritDoc */
    public function definition(string $id): ?array
    {
        $package = $this->packages->packages()[$id] ?? null;
        $record = $this->installations->all()[$id] ?? [];
        return $package && $this->available($package, $record, ['enabled', 'draining']) ? $this->describe($package, $record['state'] === 'enabled') : null;
    }

    /** @inheritDoc */
    public function get(string $id): ProviderAdapterInterface
    {
        $definition = $this->definition($id);
        if ($definition === null) {
            throw new BusinessException('所选对接平台不可用，请检查插件安装、启用状态和版本', ApiErrorCode::CONFLICT, 409);
        }
        $package = $this->packages->packages()[$id];
        try {
            PluginDependencyLoader::load($package['path'], 'plugins\\provider\\' . $id . '\\');
            $entry = $package['entry'];
            $adapter = new $entry(new ScopedPluginHttpClient($this->http, $id, $package['validated_network']));
            return new SdkProviderAdapterBridge($id, $adapter, array_column($package['catalogs'], 'kind'));
        } catch (Throwable $error) {
            throw new BusinessException('对接插件无法加载，请查看插件管理并重启服务', ApiErrorCode::SERVICE_UNAVAILABLE, 503, previous: $error);
        }
    }

    /** @param array<string, mixed> $package 包 @param array<string, mixed> $record 安装 @param list<string> $states 可用状态 @return bool 审核结果 */
    private function available(array $package, array $record, array $states): bool
    {
        return $package['category'] === 'provider' && in_array($record['state'] ?? '', $states, true)
            && ($record['package_hash'] ?? '') === $package['package_hash'];
    }

    /** @param array<string, mixed> $package 内部资料 @return array<string, mixed> 保持供应 HTTP 契约的白名单资料 */
    private function describe(array $package, bool $accepting): array
    {
        return [
            'accepting' => $accepting, 'id' => $package['id'], 'plugin_id' => $package['id'], 'name' => $package['name'],
            'version' => $package['version'], 'sdk_version' => $package['requires']['provider_sdk'],
            'requires' => array_intersect_key($package['requires'], array_flip(['core_min', 'core_max_exclusive'])),
            'configuration_fields' => $package['configuration_fields'], 'catalogs' => $package['catalogs'],
            'capabilities' => ['product_types' => $package['provider']['product_types'],
                'type_version' => $package['provider']['type_version'], 'catalog' => true, 'production_ready' => false, 'operations' => []],
            'manifest_hash' => $package['package_hash'],
        ];
    }
}
