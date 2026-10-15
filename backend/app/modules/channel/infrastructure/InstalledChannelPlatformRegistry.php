<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure;

use app\exception\BusinessException;
use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\modules\plugin\contract\PluginPackageRegistryInterface;
use app\modules\plugin\infrastructure\ScopedPluginHttpClient;
use app\modules\plugin\infrastructure\PluginDependencyLoader;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\contract\ConfigurablePluginInterface;
use app\shared\plugin\dto\Configuration;
use GuzzleHttp\ClientInterface;
use Throwable;

/** 目录展示注册器已接受的本地包；保存和执行仍只允许已安装、启用且摘要一致的包。 */
final readonly class InstalledChannelPlatformRegistry implements ChannelPlatformRegistryInterface
{
    /**
     * @param PluginPackageRegistryInterface $packages 已验证包
     * @param PluginInstallationRepositoryInterface $installations 实时安装状态
     * @param ClientInterface $http 受限客户端的底层依赖
     */
    public function __construct(
        private PluginPackageRegistryInterface $packages,
        private PluginInstallationRepositoryInterface $installations,
        private ClientInterface $http,
    ) {
    }

    /** @inheritDoc */
    public function catalog(string $category): array
    {
        $records = $this->installations->all();
        $result = [];
        foreach ($this->packages->packages() as $id => $package) {
            if ($package['category'] === $category) {
                $result[] = $this->describe($package) + [
                    'platform_available' => $this->available($category, $package, $records[$id] ?? []),
                ];
            }
        }
        return $result;
    }

    /** @inheritDoc */
    public function definitions(string $category): array
    {
        $records = $this->installations->all();
        $result = [];
        foreach ($this->packages->packages() as $id => $package) {
            if ($this->available($category, $package, $records[$id] ?? [])) {
                $result[] = $this->describe($package);
            }
        }
        return $result;
    }

    /** @inheritDoc */
    public function definition(string $category, string $id): ?array
    {
        $package = $this->packages->packages()[$id] ?? null;
        $record = $this->installations->all()[$id] ?? [];
        return $package !== null && $this->available($category, $package, $record)
            ? $this->describe($package) : null;
    }

    /** @inheritDoc */
    public function normalize(string $category, string $id, array $values): array
    {
        if ($this->definition($category, $id) === null) {
            throw new BusinessException('服务平台未安装、未启用或版本不可用', ApiErrorCode::CONFLICT, 409);
        }
        $package = $this->packages->packages()[$id];
        try {
            PluginDependencyLoader::load($package['path'], 'plugins\\' . $category . '\\' . $id . '\\');
            $entry = $package['entry'];
            $adapter = $category === 'provider'
                ? new $entry(new ScopedPluginHttpClient($this->http, $id, $package['validated_network']))
                : new $entry();
            if (!$adapter instanceof ConfigurablePluginInterface) {
                throw new BusinessException('服务平台不支持配置校验', ApiErrorCode::CONFLICT, 409);
            }
            return $adapter->validateConfiguration(new Configuration($values))->values;
        } catch (Throwable $error) {
            throw new BusinessException('服务平台配置校验未完成，请检查配置或应用状态',
                ApiErrorCode::VALIDATION_FAILED, 422, previous: $error);
        }
    }

    /**
     * @param string $category 目标类别
     * @param array<string, mixed> $package 包资料
     * @param array<string, mixed> $record 安装资料
     * @return bool 只允许同类别、启用且摘要一致的包
     */
    private function available(string $category, array $package, array $record): bool
    {
        return $package['category'] === $category && ($record['category'] ?? '') === $category
            && ($record['state'] ?? '') === 'enabled'
            && ($record['package_hash'] ?? '') === $package['package_hash'];
    }

    /**
     * @param array<string, mixed> $package 内部包资料
     * @return array<string, mixed> 公开声明
     */
    private function describe(array $package): array
    {
        return array_intersect_key($package, array_flip([
            'id', 'name', 'category', 'version', 'configuration_fields', 'package_hash', 'template_review',
        ]));
    }
}
