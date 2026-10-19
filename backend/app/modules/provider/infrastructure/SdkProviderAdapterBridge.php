<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure;

use app\exception\BusinessException;
use app\modules\provider\contract\ProviderAdapterInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\contract\ProviderAdapterInterface as SdkAdapter;
use app\shared\plugin\dto\{CatalogQuery, Configuration, ConnectionContext, ProviderBinding};
use app\shared\plugin\exception\PluginException;
use Throwable;

/** 将宿主内部供应端口映射到统一的插件 SDK v1，转换 DTO 和业务异常。 */
final readonly class SdkProviderAdapterBridge implements \app\modules\provider\contract\ProviderVncAdapterInterface, \app\modules\provider\contract\ProviderInstanceOperationsAdapterInterface, ProviderAdapterInterface, \app\modules\provider\contract\ProviderLifecycleAdapterInterface
{
    /**
     * 接收固定 SDK 实例，不接受动态方法名。
     * @param string $pluginId 稳定身份
     * @param SdkAdapter $adapter 类别固定入口
     * @param list<string> $catalogs 允许查询的目录
     */
    public function __construct(private string $pluginId, private SdkAdapter $adapter, private array $catalogs) {}

    /** @inheritDoc */
    public function supportsVnc(): bool
    {
        return $this->adapter instanceof \app\shared\plugin\contract\ProviderVncInterface;
    }

    /** @inheritDoc */
    public function openVnc(array $config, \app\shared\plugin\dto\provider\InstanceIdentity $instance): \app\shared\plugin\dto\provider\VncConnection
    {
        if (!$this->supportsVnc()) {
            throw new PluginException('原供应插件不支持VNC', 'vnc_unsupported');
        }
        return $this->adapter->openVnc(new ConnectionContext($this->pluginId, new Configuration($config)), $instance);
    }

    /** @inheritDoc */
    public function instanceOperationActions(): array
    {
        return $this->adapter instanceof \app\shared\plugin\contract\ProviderInstanceOperationsInterface
            ? array_values(array_intersect(['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'], $this->adapter->instanceOperationActions())) : [];
    }

    /** @inheritDoc */
    public function instanceOperation(array $config, \app\shared\plugin\dto\provider\InstanceOperationRequest $request, bool $query): \app\shared\plugin\dto\provider\LifecycleResult
    {
        if (!in_array($request->action, $this->instanceOperationActions(), true)) {
            throw new PluginException('供应插件不支持此运维操作', 'operation_unsupported');
        }
        $context = new ConnectionContext($this->pluginId, new Configuration($config));
        return $query ? $this->adapter->queryInstanceOperation($context, $request) : $this->adapter->operateInstance($context, $request);
    }

    /** @inheritDoc */
    public function supportsLifecycle(): bool
    {
        return $this->adapter instanceof \app\shared\plugin\contract\ProviderLifecycleInterface;
    }

    /** @inheritDoc */
    public function lifecycle(array $config, \app\shared\plugin\dto\provider\LifecycleRequest $request, bool $query): \app\shared\plugin\dto\provider\LifecycleResult
    {
        if (!$this->supportsLifecycle()) {
            throw new PluginException('原供应插件不支持生命周期操作', 'lifecycle_unsupported');
        }
        $context = new ConnectionContext($this->pluginId, new Configuration($config));
        return $query ? $this->adapter->queryLifecycle($context, $request) : $this->adapter->lifecycle($context, $request);
    }

    /** @inheritDoc */
    public function provision(array $config, \app\shared\plugin\dto\provider\ProvisionRequest $request, bool $query): \app\shared\plugin\dto\provider\ProvisionResult
    {
        if (!$this->adapter instanceof \app\shared\plugin\contract\ProviderProvisioningInterface) throw new PluginException('供应插件未实现生产契约', 'provider_unavailable');
        $context = new ConnectionContext($this->pluginId, new Configuration($config));
        // 保留稳定错误分类；应用层只记录分类，不记录外部异常原文。
        return $query ? $this->adapter->queryProvision($context, $request) : $this->adapter->provision($context, $request);
    }

    /** @inheritDoc */
    public function normalizeConfiguration(array $configuration): array
    {
        return $this->call(fn (): array => $this->adapter->validateConfiguration(new Configuration($configuration))->values);
    }

    /** @inheritDoc */
    public function test(array $config): void
    {
        $this->call(fn () => $this->adapter->testConnection(new ConnectionContext($this->pluginId, new Configuration($config))));
    }

    /** @inheritDoc */
    public function catalog(array $config, string $kind, array $query): array
    {
        if (!in_array($kind, $this->catalogs, true)) {
            throw new BusinessException('所选对接平台没有声明此类资源');
        }
        $page = $this->call(fn () => $this->adapter->listCatalog(
            new ConnectionContext($this->pluginId, new Configuration($config)),
            new CatalogQuery($kind, $query['page'] ?? 1, $query['per_page'] ?? 50),
        ));
        if (count($page->entries) > ($query['per_page'] ?? 50) || $page->total < count($page->entries)
            || $page->page !== ($query['page'] ?? 1)) {
            throw new BusinessException('插件返回的目录分页无效', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }
        foreach ($page->entries as $entry) {
            if (array_diff(array_keys($entry), ['external_id', 'name', 'metadata']) !== []
                || !is_string($entry['external_id'] ?? null) || $entry['external_id'] === '' || mb_strlen($entry['external_id']) > 128
                || !is_string($entry['name'] ?? null) || mb_strlen($entry['name']) > 128 || !is_array($entry['metadata'] ?? null)) {
                throw new BusinessException('插件返回的资源资料无效', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
            }
            if (count($entry['metadata']) > 32 || strlen(json_encode($entry['metadata'], JSON_THROW_ON_ERROR)) > 16384) {
                throw new BusinessException('插件返回的资源元数据过大', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
            }
            foreach ($entry['metadata'] as $key => $value) {
                if (!is_string($key) || strlen($key) > 64 || (is_string($value) && mb_strlen($value) > 2048)) {
                    throw new BusinessException('插件返回的资源元数据无效', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
                }
                if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                    throw new BusinessException('插件返回的资源元数据无效', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
                }
            }
        }
        return ['data' => $page->entries, 'total' => $page->total, 'page' => $page->page];
    }

    /** @inheritDoc */
    public function validateBinding(array $resources, array $mapping, array $catalog): void
    {
        $this->call(fn () => $this->adapter->validateBinding(new ProviderBinding($resources, $mapping, $catalog)));
    }

    /**
     * 转换固定方法调用异常，不公开底层路径或报文。
     * @template T
     * @param callable(): T $operation 宿主编写的固定调用
     * @return T 调用结果
     * @throws BusinessException 安全业务或外部错误
     */
    private function call(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PluginException $error) {
            $validation = in_array($error->reason, ['invalid_configuration', 'invalid_binding', 'capability_denied'], true);
            throw new BusinessException($error->getMessage(),
                $validation ? ApiErrorCode::VALIDATION_FAILED : ApiErrorCode::SERVICE_UNAVAILABLE,
                $validation ? 422 : 503, previous: $error);
        } catch (Throwable $error) {
            throw new BusinessException('对接插件执行失败，请检查插件兼容性', ApiErrorCode::SERVICE_UNAVAILABLE, 503, previous: $error);
        }
    }
}
