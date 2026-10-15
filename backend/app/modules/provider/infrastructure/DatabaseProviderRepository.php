<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure;

use app\exception\BusinessException;
use app\modules\provider\contract\{ProviderRepositoryInterface, ProviderAdapterRegistryInterface};
use app\modules\provider\infrastructure\model\{ProviderConnectionModel, ProviderCatalogEntryModel, ProviderResourceMappingModel};
use app\shared\contract\{SensitiveDataCipherInterface, TransactionManagerInterface};
use app\shared\http\ApiErrorCode;
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;

/** 凭据密文保存；变更配置后清除测试结论与外部目录。 */
final readonly class DatabaseProviderRepository implements ProviderRepositoryInterface
{
    public function __construct(private SensitiveDataCipherInterface $cipher, private TransactionManagerInterface $transactions, private ProviderAdapterRegistryInterface $adapters, private PluginInstallationRepositoryInterface $installations) {}

    public function list(): array
    {
        $definitions = array_column($this->adapters->definitions(true), null, 'id');
        return ProviderConnectionModel::query()->orderByDesc('id')->get()->map(fn (ProviderConnectionModel $row): array => $this->profile($row, $definitions))->all();
    }

    public function find(int $id): ?array
    {
        $row = ProviderConnectionModel::query()->find($id);
        return $row ? $this->profile($row) : null;
    }

    public function credentials(int $id): array
    {
        $row = ProviderConnectionModel::query()->find($id) ?? throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404);
        return json_decode($this->cipher->decrypt($row->credentials_encrypted), true, flags: JSON_THROW_ON_ERROR);
    }

    public function save(?int $id, array $data): array
    {
        return $this->transactions->run(function () use ($id, $data): array {
            $this->installations->lockAccepting($data['adapter_id']);
            $row = $id === null ? new ProviderConnectionModel() : (ProviderConnectionModel::query()->lockForUpdate()->find($id)
                ?? throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404));
            if ($id !== null && $row->revision !== $data['revision']) throw new BusinessException('连接已被修改，请刷新后重试', ApiErrorCode::CONFLICT, 409);
            $old = $id === null ? [] : $this->credentials($id);
            if ($id !== null && $row->adapter_id !== $data['adapter_id']) throw new BusinessException('对接平台创建后不能更换，请添加新的供应商');
            $definition = $this->adapters->definition($data['adapter_id']) ?? throw new BusinessException('所选对接平台不可用');
            $config = $data['configuration'];
            $capabilities = $this->capabilities($definition);
            $changed = $old !== $config || ($row->capabilities['_adapter']['manifest_hash'] ?? null) !== $definition['manifest_hash'];
            if ($changed) {
                $row->credentials_encrypted = $this->cipher->encrypt(json_encode($config, JSON_THROW_ON_ERROR));
                $row->tested_at = null;
                if ($id !== null) {
                    ProviderCatalogEntryModel::query()->where('connection_id', $id)->delete();
                    ProviderResourceMappingModel::query()->where('connection_id', $id)->delete();
                }
            }
            if ($data['status'] === 1 && $row->tested_at === null) throw new BusinessException('请先保存配置并测试连接，再启用');
            $row->fill(['name' => $data['name'], 'adapter_id' => $data['adapter_id'], 'status' => $data['status'],
                'revision' => ($row->revision ?? 0) + 1, 'capabilities' => $capabilities]);
            $row->save();
            return $this->profile($row);
        });
    }

    public function setStatus(int $id, int $revision, int $status): array
    {
        return $this->transactions->run(function () use ($id, $revision, $status): array {
            if ($status === 1) {
                $current = ProviderConnectionModel::query()->find($id) ?? throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404);
                $this->installations->lockAccepting($current->adapter_id);
                if ($this->adapters->definition($current->adapter_id) === null) {
                    throw new BusinessException('对接插件需要审核更新', ApiErrorCode::CONFLICT, 409);
                }
            }
            $row = ProviderConnectionModel::query()->lockForUpdate()->find($id) ?? throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404);
            if ($row->revision !== $revision) throw new BusinessException('连接已被修改，请刷新后重试', ApiErrorCode::CONFLICT, 409);
            if ($status === 1 && $this->profile($row)['requires_retest']) throw new BusinessException('请先测试连接，测试成功后再启用');
            $row->status = $status;
            $row->revision++;
            $row->save();
            return $this->profile($row);
        });
    }

    public function markTested(int $id, int $revision): void
    {
        $this->transactions->run(function () use ($id, $revision): void {
            $row = ProviderConnectionModel::query()->lockForUpdate()->find($id) ?? throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404);
            if ($row->revision !== $revision) throw new BusinessException('测试期间配置已变化，请重新测试', ApiErrorCode::CONFLICT, 409);
            $definition = $this->adapters->definition($row->adapter_id) ?? throw new BusinessException('所选对接平台不可用');
            if (($row->capabilities['_adapter']['manifest_hash'] ?? null) !== $definition['manifest_hash']) {
                ProviderCatalogEntryModel::query()->where('connection_id', $id)->delete();
                ProviderResourceMappingModel::query()->where('connection_id', $id)->delete();
            }
            $row->capabilities = $this->capabilities($definition);
            $row->tested_at = date('Y-m-d H:i:s');
            $row->revision++;
            $row->save();
        });
    }

    public function cacheCatalog(int $id, string $kind, int $revision, array $entries): void
    {
        $this->transactions->run(function () use ($id, $kind, $revision, $entries): void {
            $row = ProviderConnectionModel::query()->lockForUpdate()->find($id);
            if (!$row || $row->revision !== $revision) throw new BusinessException('供应商配置已变化，请重新获取资源', ApiErrorCode::CONFLICT, 409);
            foreach ($entries as $entry) ProviderCatalogEntryModel::query()->updateOrCreate(
                ['connection_id' => $id, 'kind' => $kind, 'external_id' => $entry['external_id']], ['name' => $entry['name'], 'metadata' => $entry['metadata']]);
        });
    }

    public function mappings(int $id): array
    {
        return ProviderResourceMappingModel::query()->where('connection_id', $id)->orderBy('id')->get(['id', 'connection_id', 'resource_kind', 'local_id', 'external_id', 'scope_zone_id'])
            ->map(static fn (ProviderResourceMappingModel $r): array => ['id' => $r->id, 'connection_id' => $r->connection_id,
                'resource_kind' => $r->resource_kind, 'local_id' => $r->local_id, 'external_id' => $r->external_id, 'scope_zone_id' => $r->scope_zone_id])->all();
    }

    public function catalogEntries(int $id): array
    {
        return ProviderCatalogEntryModel::query()->where('connection_id', $id)->get()
            ->map(static fn (ProviderCatalogEntryModel $row): array => ['kind' => $row->kind, 'external_id' => $row->external_id,
                'name' => $row->name, 'metadata' => $row->metadata])->all();
    }

    public function map(int $id, string $kind, int $localId, string $externalId, string $catalogKind, int $scopeZoneId = 0): void
    {
        $this->transactions->run(function () use ($id, $kind, $localId, $externalId, $catalogKind, $scopeZoneId): void {
            $current = ProviderConnectionModel::query()->find($id) ?? throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404);
            $this->installations->lockAccepting($current->adapter_id);
            $row = ProviderConnectionModel::query()->lockForUpdate()->find($id);
            if (!$row) throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404);
            if ($this->profile($row)['requires_retest']) throw new BusinessException('连接配置已变化，请重新测试后设置资源对应关系', ApiErrorCode::CONFLICT, 409);
            if (!ProviderCatalogEntryModel::query()->where('connection_id', $id)->where('kind', $catalogKind)->where('external_id', $externalId)->exists()) {
                throw new BusinessException('请先获取供应商资源，再选择需要对应的资源');
            }
            ProviderResourceMappingModel::query()->updateOrCreate(['connection_id' => $id, 'resource_kind' => $kind, 'local_id' => $localId, 'scope_zone_id' => $scopeZoneId], ['external_id' => $externalId]);
        });
    }

    /** @param array<string, mixed> $definition 插件声明 @return array<string, mixed> 无凭据的能力及配置字段快照 */
    private function capabilities(array $definition): array
    {
        return $definition['capabilities'] + ['_adapter' => array_intersect_key($definition, array_flip(['id', 'plugin_id', 'name', 'version', 'sdk_version', 'manifest_hash'])),
            '_configuration_fields' => $definition['configuration_fields']];
    }

    /** @param ProviderConnectionModel $row 连接 @return array<string, mixed> 不含密码、令牌或其他私密字段的资料 */
    private function profile(ProviderConnectionModel $row, ?array $definitions = null): array
    {
        $config = json_decode($this->cipher->decrypt($row->credentials_encrypted), true, flags: JSON_THROW_ON_ERROR);
        $definition = $definitions === null ? $this->adapters->definition($row->adapter_id) : ($definitions[$row->adapter_id] ?? null);
        // 历史连接没有字段快照时只公开原有非私密字段，未知配置始终不公开。
        $fields = $row->capabilities['_configuration_fields'] ?? [
            ['key' => 'api_url', 'type' => 'url'], ['key' => 'username', 'type' => 'text'], ['key' => 'password', 'type' => 'password'],
        ];
        $public = []; $secrets = [];
        $currentSecrets = array_column(array_filter($definition['configuration_fields'] ?? [], static fn (array $field): bool => $field['type'] === 'password'), 'key');
        foreach ($fields as $field) {
            if ($field['type'] === 'password' || in_array($field['key'], $currentSecrets, true)) $secrets[$field['key']] = ($config[$field['key']] ?? '') !== '';
            else $public[$field['key']] = $config[$field['key']] ?? '';
        }
        $requiresRetest = $definition === null || $row->tested_at === null
            || ($row->capabilities['_adapter']['manifest_hash'] ?? null) !== $definition['manifest_hash'];
        return ['id' => $row->id, 'name' => $row->name, 'adapter_id' => $row->adapter_id,
            'adapter_name' => $definition['name'] ?? ($row->capabilities['_adapter']['name'] ?? $row->adapter_id),
            'adapter_version' => $row->capabilities['_adapter']['version'] ?? null, 'adapter_available' => $definition !== null,
            'adapter_accepting' => $definition['accepting'] ?? false, 'adapter_catalogs' => $definition['catalogs'] ?? [], 'requires_retest' => $requiresRetest,
            'configuration' => (object) $public, 'configured_secrets' => (object) $secrets,
            'status' => $row->status, 'revision' => $row->revision, 'api_url' => $public['api_url'] ?? '', 'username' => $public['username'] ?? '',
            'has_password' => $secrets['password'] ?? false, 'tested_at' => $row->tested_at?->format('Y-m-d H:i:s'),
            'capabilities' => array_intersect_key($row->capabilities, array_flip(['type_version', 'product_types', 'catalog', 'production_ready', 'operations'])),
            'created_at' => $row->created_at->format('Y-m-d H:i:s')];
    }
}
