<?php

declare(strict_types=1);

namespace app\modules\provider\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\contract\ComputeResourceRepositoryInterface;
use app\modules\compute\domain\ComputeResources;
use app\modules\provider\contract\{ProviderRepositoryInterface, ProviderAdapterRegistryInterface};
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 连接、只读目录和映射的公开管理门面；外部调用不持有数据库事务。 */
final readonly class ManageProviderConnectionsService
{
    public function __construct(private AdminAccessControlService $access, private ProviderRepositoryInterface $connections,
        private ProviderAdapterRegistryInterface $adapters, private ComputeResourceRepositoryInterface $resources) {}

    /** @param AdminAccount $actor 操作人 @return list<array<string, mixed>> 可选择的已启用插件 */
    public function adapters(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.view');
        return $this->adapters->definitions();
    }

    /** @param AdminAccount $actor 操作人 @return list<array<string, mixed>> 连接 */
    public function list(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.view');
        return $this->connections->list();
    }

    /** @param AdminAccount $actor 操作人 @param int|null $id 标识 @param array<string, mixed> $data 配置 @return array<string, mixed> 连接 */
    public function save(AdminAccount $actor, ?int $id, array $data): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.' . ($id === null ? 'create' : 'update'));
        if ($id !== null && $this->require($id)['adapter_id'] !== $data['adapter_id']) throw new BusinessException('对接平台创建后不能更换，请添加新的供应商');
        $adapter = $this->adapters->get($data['adapter_id']);
        $definition = $this->adapters->definition($data['adapter_id']);
        if (!in_array($data['adapter_id'], array_column($this->adapters->definitions(), 'id'), true)) {
            throw new BusinessException('对接插件已停止接单，不能新增或更改供应商', ApiErrorCode::CONFLICT, 409);
        }
        $old = $id === null ? [] : $this->connections->credentials($id);
        $configuration = $data['configuration'];
        $clearSecrets = $data['clear_secrets'] ?? [];
        $secretKeys = array_column(array_filter($definition['configuration_fields'], static fn (array $field): bool => $field['type'] === 'password'), 'key');
        if (array_diff($clearSecrets, $secretKeys) !== []) {
            throw new BusinessException('只能明确清除已声明的私密字段', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        foreach ($definition['configuration_fields'] as $field) {
            $key = $field['key'];
            if (in_array($key, $clearSecrets, true)) {
                $configuration[$key] = '';
            } elseif ($field['type'] === 'password' && ($configuration[$key] ?? '') === '') {
                $configuration[$key] = $old[$key] ?? '';
            }
        }
        $configuration = $this->validateConfiguration($definition, $configuration);
        $data['configuration'] = $this->validateConfiguration($definition, $adapter->normalizeConfiguration($configuration));
        return $this->connections->save($id, $data);
    }

    /** @param AdminAccount $actor 操作人 @param int $id 连接 @param int $revision 版本 @param int $status 启停 @return array<string, mixed> 连接资料 */
    public function status(AdminAccount $actor, int $id, int $revision, int $status): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.update');
        return $this->connections->setStatus($id, $revision, $status);
    }

    /** @param AdminAccount $actor 操作人 @param int $id 标识 @return array<string, mixed> 测试后资料 */
    public function test(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.test');
        $row = $this->require($id);
        $adapter = $this->adapters->get($row['adapter_id']);
        $configuration = $this->validateConfiguration($this->adapters->definition($row['adapter_id']), $this->connections->credentials($id));
        $adapter->test($adapter->normalizeConfiguration($configuration));
        $this->connections->markTested($id, $row['revision']);
        return $this->require($id);
    }

    /** @param AdminAccount $actor 操作人 @param int $id 标识 @param string $kind 目录 @param array<string, int> $query 筛选 @return array<string, mixed> 目录 */
    public function catalog(AdminAccount $actor, int $id, string $kind, array $query): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.sync');
        $row = $this->require($id);
        if ($row['requires_retest']) throw new BusinessException('请先测试连接，再获取供应商资源');
        $definition = $this->adapters->definition($row['adapter_id']);
        if (!$definition || !in_array($kind, array_column($definition['catalogs'], 'kind'), true)) throw new BusinessException('所选对接平台不支持获取此类资源');
        $result = $this->adapters->get($row['adapter_id'])->catalog($this->connections->credentials($id), $kind, $query);
        $this->connections->cacheCatalog($id, $kind, $row['revision'], $result['data']);
        return $result;
    }

    /** @param AdminAccount $actor 操作人 @param int $id 标识 @return list<array<string, mixed>> 映射 */
    public function mappings(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.view');
        $this->require($id);
        return $this->connections->mappings($id);
    }

    /** @param AdminAccount $actor 操作人 @return array<string, list<array<string, mixed>>> 映射所需的本地名称与标识 */
    public function references(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.view');
        $result = [];
        foreach (ComputeResources::KINDS as $kind) {
            $result[$kind] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'name', 'zone_ids', 'group_id'])), $this->resources->list($kind));
        }
        return $result;
    }

    /** @param AdminAccount $actor 操作人 @param int $id 标识 @param string $kind 类型 @param int $localId 本地资源 @param string $externalId 外部资源 @return void 保存 */
    public function map(AdminAccount $actor, int $id, string $kind, int $localId, string $externalId, int $scopeZoneId = 0): void
    {
        $this->access->requirePermission($actor, 'admin.provider-connections.map');
        $row = $this->require($id);
        if ($row['requires_retest']) throw new BusinessException('请先测试当前供应商配置，再设置资源对应关系');
        $definition = $this->adapters->definition($row['adapter_id']);
        $catalog = array_values(array_filter($definition['catalogs'] ?? [], static fn (array $r): bool => $r['resource_kind'] === $kind))[0] ?? null;
        if ($catalog === null) throw new BusinessException('所选对接平台不支持该资源的对应设置');
        $resource = $this->resources->find($kind, $localId);
        if ($resource === null) throw new BusinessException('本系统资源不存在，请刷新后重试');
        if ($scopeZoneId > 0) {
            if (!in_array($kind, ['flavor_groups', 'flavors', 'images', 'disk_types', 'lines'], true) || $this->resources->find('zones', $scopeZoneId) === null) throw new BusinessException('该资源不支持指定的可用区对应范围');
            $zones = $resource['zone_ids'] ?? [];
            if ($kind === 'flavor_groups') foreach ($this->resources->list('flavors') as $flavor) if ($flavor['group_id'] === $localId) $zones = array_merge($zones, $flavor['zone_ids']);
            if (!in_array($scopeZoneId, $zones, true)) throw new BusinessException('本系统资源不适用于该可用区');
        }
        $this->connections->map($id, $kind, $localId, $externalId, $catalog['kind'], $scopeZoneId);
    }

    /** @param int $id 连接 @return array<string, mixed> 连接 */
    private function require(int $id): array
    {
        return $this->connections->find($id) ?? throw new BusinessException('资源供应商不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    /**
     * 宿主执行受限 Schema，插件规范化后再次验证，未知字段不能静默丢弃。
     * @param array<string, mixed> $definition 插件声明
     * @param array<string, mixed> $input 输入
     * @return array<string, string|int|bool> 配置白名单
     */
    private function validateConfiguration(array $definition, array $input): array
    {
        if (array_diff(array_keys($input), array_column($definition['configuration_fields'], 'key')) !== []) {
            throw new BusinessException('配置包含插件未声明的字段', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $values = $rules = [];
        foreach ($definition['configuration_fields'] as $field) {
            $key = $field['key'];
            $value = $input[$key] ?? $field['default'] ?? ($field['type'] === 'boolean' ? false : '');
            if (!$field['required'] && $value === '' && in_array($field['type'], ['integer', 'select'], true)) {
                continue;
            }
            $values[$key] = is_string($value) && $field['type'] !== 'password' ? trim($value) : $value;
            $rule = $field['required'] && $field['type'] !== 'boolean' ? 'required' : 'present';
            $rule .= match ($field['type']) {
                'integer' => '|integer|min:' . $field['minimum'] . '|max:' . $field['maximum'],
                'boolean' => '|boolean',
                'select' => '|string',
                default => '|string|max:' . $field['max_length'],
            };
            if ($field['type'] === 'url' && $values[$key] !== '') {
                $rule .= '|url:http,https';
            }
            if ($field['type'] === 'select' && !in_array($values[$key], array_column($field['options'], 'value'), true)) {
                throw new BusinessException('请选择有效的' . $field['label'], ApiErrorCode::VALIDATION_FAILED, 422);
            }
            $rules[$key] = $rule;
        }
        $result = Validator::make($values, $rules)->validate();
        foreach ($definition['configuration_fields'] as $field) {
            if (!array_key_exists($field['key'], $result)) {
                continue;
            }
            if ($field['type'] === 'integer') {
                $result[$field['key']] = (int) $result[$field['key']];
            } elseif ($field['type'] === 'boolean') {
                $result[$field['key']] = (bool) $result[$field['key']];
            }
        }
        return $result;
    }
}
