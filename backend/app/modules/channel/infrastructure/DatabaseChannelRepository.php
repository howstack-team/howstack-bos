<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure;

use app\exception\BusinessException;
use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\domain\ChannelCategories;
use app\modules\channel\infrastructure\model\ChannelCategorySettingModel;
use app\modules\channel\infrastructure\model\ChannelConnectionModel;
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use app\shared\infrastructure\TableQueryOrder;

/** 加密配置、版本冲突、默认通道互斥与历史保留统一在事务中处理。 */
final readonly class DatabaseChannelRepository implements ChannelRepositoryInterface
{
    /**
     * @param SensitiveDataCipherInterface $cipher 可恢复敏感配置加密
     * @param TransactionManagerInterface $transactions 默认业务连接事务
     * @param ChannelPlatformRegistryInterface $platforms 已安装平台声明
     * @param PluginInstallationRepositoryInterface $installations 插件生命周期互斥
     */
    public function __construct(
        private SensitiveDataCipherInterface $cipher,
        private TransactionManagerInterface $transactions,
        private ChannelPlatformRegistryInterface $platforms,
        private PluginInstallationRepositoryInterface $installations,
    ) {
    }

    /** @inheritDoc */
    public function catalog(string $category, array $platforms, array $filters): array
    {
        $definitions = array_column($platforms, null, 'id');
        $defaultId = ChannelCategorySettingModel::query()->where('category', $category)->value('default_channel_id');
        $connections = ChannelConnectionModel::query()->where('category', $category)
            ->whereNull('deleted_at')->orderBy('id')->get();
        $rows = [];
        $configured = [];
        foreach ($connections as $connection) {
            $platform = $definitions[$connection->plugin_id] ?? null;
            $available = $platform !== null && $platform['platform_available'];
            $rows[] = $this->profile($connection, $defaultId === null ? null : (int) $defaultId,
                $available ? $platform : null);
            $configured[$connection->plugin_id] = true;
        }
        foreach ($platforms as $platform) {
            if (!isset($configured[$platform['id']])) {
                $rows[] = [
                    'id' => 0, 'category' => $category, 'name' => $platform['name'],
                    'plugin_id' => $platform['id'], 'platform_name' => $platform['name'],
                    'platform_version' => $platform['version'], 'platform_available' => $platform['platform_available'],
                    'configured' => false, 'configuration_current' => false, 'status' => 0, 'is_default' => false,
                    'sort_order' => 0, 'description' => '', 'revision' => 0, 'created_at' => '', 'updated_at' => '',
                ];
            }
        }
        $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            $name = trim($filters['name'] ?? '');
            return ($name === '' || mb_stripos($row['platform_name'], $name) !== false || mb_stripos($row['name'], $name) !== false)
                && (($filters['plugin_id'] ?? '') === '' || $row['plugin_id'] === $filters['plugin_id'])
                && (!isset($filters['status']) || $row['status'] === (int) $filters['status']);
        }));
        $key = $filters['sort_by'] ?? 'sort_order';
        $direction = ($filters['sort_order'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($rows, static fn (array $left, array $right): int =>
            (($left[$key] <=> $right[$key]) * $direction) ?: (($left['plugin_id'] <=> $right['plugin_id']) ?: ($left['id'] <=> $right['id'])));
        return ['data' => array_slice($rows, ((int) $filters['page'] - 1) * (int) $filters['page_size'], (int) $filters['page_size']),
            'total' => count($rows)];
    }

    /** @inheritDoc */
    public function list(string $category, array $filters): array
    {
        $query = ChannelConnectionModel::query()->where('category', $category)->whereNull('deleted_at');
        if (($filters['name'] ?? '') !== '') {
            $query->where('name', 'like', '%' . addcslashes(trim($filters['name']), '%_\\') . '%');
        }
        if (($filters['plugin_id'] ?? '') !== '') {
            $query->where('plugin_id', $filters['plugin_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', (int) $filters['status']);
        }
        $total = (clone $query)->count();
        TableQueryOrder::apply($query, $filters, [
            'sort_order' => 'sort_order', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ], ['sort_order' => 'asc', 'id' => 'desc']);
        $defaultId = ChannelCategorySettingModel::query()->where('category', $category)->value('default_channel_id');
        $definitions = array_column($this->platforms->definitions($category), null, 'id');
        $rows = $query->forPage((int) $filters['page'], (int) $filters['page_size'])->get();
        return ['data' => $rows->map(fn (ChannelConnectionModel $row): array =>
            $this->profile($row, $defaultId === null ? null : (int) $defaultId, $definitions[$row->plugin_id] ?? null))->all(), 'total' => $total];
    }

    /** @inheritDoc */
    public function find(string $category, int $id): array
    {
        $row = $this->require($category, $id);
        $definition = $this->platforms->definition($category, $row->plugin_id);
        $defaultId = ChannelCategorySettingModel::query()->where('category', $category)->value('default_channel_id');
        $config = $this->decrypt($row);
        $secrets = $row->secret_keys;
        foreach ($definition['configuration_fields'] ?? [] as $field) {
            if ($field['type'] === 'password') {
                $secrets[] = $field['key'];
            }
        }
        $public = $configured = [];
        $fields = $row->platform_snapshot['configuration_fields'];
        foreach ($fields as &$field) {
            $key = $field['key'];
            if (in_array($key, $secrets, true)) {
                $field['type'] = 'password';
                unset($field['default']);
                $configured[$key] = ($config[$key] ?? '') !== '';
            } else {
                $public[$key] = $config[$key] ?? $field['default'] ?? '';
            }
        }
        unset($field);
        return $this->profile($row, $defaultId === null ? null : (int) $defaultId, $definition) + [
            'configuration' => (object) $public,
            'configured_secrets' => (object) $configured,
            'configuration_fields' => $fields,
        ];
    }

    /** @inheritDoc */
    public function configuration(string $category, int $id): array
    {
        return $this->decrypt($this->require($category, $id));
    }

    /** @inheritDoc */
    public function save(string $category, ?int $id, array $data, array $platform): array
    {
        if ($data['is_default'] && !isset(ChannelCategories::DEFAULT_LABELS[$category])) {
            throw new BusinessException('此类通道由业务选择，不支持设置统一默认', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        return $this->transactions->run(function () use ($category, $id, $data, $platform): array {
            // 先锁安装身份，再锁分类、连接；与插件停用/升级的锁顺序一致。
            $this->installations->lockAccepting($data['plugin_id']);
            $setting = $this->lockCategory($category);
            // 固定平台首次配置与分类锁共享事务，两个管理员同时保存也不能重复创建。
            if ($id === null && ($data['platform_configuration'] ?? false)
                && ChannelConnectionModel::query()->where('category', $category)
                    ->where('plugin_id', $data['plugin_id'])->whereNull('deleted_at')->exists()) {
                throw new BusinessException('该插件已完成配置，请刷新后编辑', ApiErrorCode::CONFLICT, 409);
            }
            $row = $id === null ? new ChannelConnectionModel() : $this->require($category, $id, true);
            $this->revision($row, $data['revision']);
            if ($id !== null && $row->plugin_id !== $data['plugin_id']) {
                throw new BusinessException('服务平台不能更换，请配置对应插件', ApiErrorCode::CONFLICT, 409);
            }
            $current = $this->platforms->definition($category, $data['plugin_id']);
            if ($current === null || $current['package_hash'] !== $platform['package_hash']) {
                throw new BusinessException('服务平台状态或版本已变化，请刷新重试', ApiErrorCode::CONFLICT, 409);
            }
            $secretKeys = $row->secret_keys ?? [];
            foreach ($platform['configuration_fields'] as $field) {
                if ($field['type'] === 'password') {
                    $secretKeys[] = $field['key'];
                }
            }
            $row->fill([
                'category' => $category, 'name' => $data['name'], 'plugin_id' => $data['plugin_id'],
                'platform_snapshot' => $platform, 'secret_keys' => array_values(array_unique($secretKeys)),
                'configuration_encrypted' => $this->cipher->encrypt(json_encode($data['configuration'], JSON_THROW_ON_ERROR)),
                'status' => $data['status'], 'sort_order' => $data['sort_order'],
                'description' => $data['description'], 'revision' => ($row->revision ?? 0) + 1,
            ]);
            $row->save();
            if ($data['is_default']) {
                $setting->default_channel_id = $row->id;
            } elseif ($setting->default_channel_id === $row->id) {
                $setting->default_channel_id = null;
            }
            $setting->save();
            return $this->find($category, $row->id);
        });
    }

    /** @inheritDoc */
    public function status(string $category, int $id, int $revision, int $status): array
    {
        return $this->transactions->run(function () use ($category, $id, $revision, $status): array {
            if ($status === 1) {
                $current = $this->require($category, $id);
                $this->installations->lockAccepting($current->plugin_id);
            }
            $setting = $this->lockCategory($category);
            $row = $this->require($category, $id, true);
            $this->revision($row, $revision);
            $platform = $this->platforms->definition($category, $row->plugin_id);
            if ($status === 1 && ($platform === null || $platform['package_hash'] !== $row->platform_snapshot['package_hash'])) {
                throw new BusinessException('服务平台或配置版本不可用，请先重新保存配置', ApiErrorCode::CONFLICT, 409);
            }
            $row->status = $status;
            $row->revision++;
            $row->save();
            if ($status === 0 && $setting->default_channel_id === $id) {
                $setting->default_channel_id = null;
                $setting->save();
            }
            return $this->find($category, $id);
        });
    }

    /** @inheritDoc */
    public function archive(string $category, int $id, int $revision): void
    {
        $this->transactions->run(function () use ($category, $id, $revision): void {
            $setting = $this->lockCategory($category);
            $row = $this->require($category, $id, true);
            $this->revision($row, $revision);
            if ($row->status !== 0) {
                throw new BusinessException('请先禁用通道，再删除配置', ApiErrorCode::CONFLICT, 409);
            }
            $row->deleted_at = date('Y-m-d H:i:s');
            $row->revision++;
            $row->save();
            if ($setting->default_channel_id === $id) {
                $setting->default_channel_id = null;
                $setting->save();
            }
        });
    }

    /**
     * @param string $category 类别
     * @param int $id 标识
     * @param bool $lock 是否锁行
     * @return ChannelConnectionModel 在用连接
     */
    private function require(string $category, int $id, bool $lock = false): ChannelConnectionModel
    {
        $query = ChannelConnectionModel::query()->where('category', $category)->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->find($id) ?? throw new BusinessException('通道不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    /**
     * @param string $category 类别
     * @return ChannelCategorySettingModel 已锁定的分类行
     */
    private function lockCategory(string $category): ChannelCategorySettingModel
    {
        return ChannelCategorySettingModel::query()->where('category', $category)->lockForUpdate()->first()
            ?? throw new BusinessException('通道分类尚未初始化，请执行系统数据库迁移', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
    }

    /**
     * @param ChannelConnectionModel $row 连接
     * @param int $revision 输入版本
     * @return void
     */
    private function revision(ChannelConnectionModel $row, int $revision): void
    {
        if (($row->revision ?? 0) !== $revision) {
            throw new BusinessException('通道已被修改，请刷新后重试', ApiErrorCode::CONFLICT, 409);
        }
    }

    /**
     * @param ChannelConnectionModel $row 连接
     * @return array<string, string|int|bool> 内部明文
     */
    private function decrypt(ChannelConnectionModel $row): array
    {
        return json_decode($this->cipher->decrypt($row->configuration_encrypted), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param ChannelConnectionModel $row 连接
     * @param int|null $defaultId 默认通道
     * @param array<string, mixed>|null $platform 当前可用平台
     * @return array<string, mixed> 不含配置值的列表资料
     */
    private function profile(ChannelConnectionModel $row, ?int $defaultId, ?array $platform): array
    {
        return [
            'id' => $row->id, 'category' => $row->category, 'name' => $row->name,
            'plugin_id' => $row->plugin_id, 'platform_name' => $row->platform_snapshot['name'],
            'platform_version' => $row->platform_snapshot['version'],
            'platform_available' => $platform !== null,
            'configuration_current' => $platform !== null && $platform['package_hash'] === $row->platform_snapshot['package_hash'],
            'configured' => true, 'status' => $row->status,
            'is_default' => isset(ChannelCategories::DEFAULT_LABELS[$row->category]) && $row->status === 1 && $defaultId === $row->id,
            'sort_order' => $row->sort_order, 'description' => $row->description, 'revision' => $row->revision,
            'created_at' => $row->created_at->format('Y-m-d H:i:s'), 'updated_at' => $row->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
