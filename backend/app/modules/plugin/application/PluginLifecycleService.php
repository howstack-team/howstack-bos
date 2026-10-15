<?php

declare(strict_types=1);

namespace app\modules\plugin\application;

use app\exception\BusinessException;
use app\modules\plugin\contract\{PluginInstallationRepositoryInterface, PluginPackageRegistryInterface};
use app\shared\http\ApiErrorCode;

/** 宿主生命周期用例；只登记经审核本地包，不接受上传代码或任意路径。 */
final readonly class PluginLifecycleService
{
    /**
     * 注入受控包注册与安装仓储。
     * @param PluginPackageRegistryInterface $packages 包快照
     * @param PluginInstallationRepositoryInterface $installations 安装记录
     */
    public function __construct(private PluginPackageRegistryInterface $packages, private PluginInstallationRepositoryInterface $installations) {}

    /** @return list<array<string, mixed>> 白名单包状态，不含入口或文件系统路径 */
    public function list(): array
    {
        $records = $this->installations->all();
        $packages = $this->packages->packages();
        $failures = [];
        foreach ($this->packages->failures() as $failure) {
            $failures[$failure['id']] = $failure;
        }
        $result = [];
        foreach (array_unique(array_merge(array_keys($packages), array_keys($records), array_keys($failures))) as $id) {
            $package = $packages[$id] ?? null;
            $record = $records[$id] ?? null;
            $changed = $package !== null && $record !== null && $package['package_hash'] !== $record['package_hash'];
            $result[] = ['id' => $id, 'name' => $package['name'] ?? $id,
                'category' => $package['category'] ?? $record['category'] ?? $failures[$id]['category'],
                'version' => $package['version'] ?? $record['version'] ?? '-',
                'installed_version' => $record['version'] ?? null, 'publisher' => $package['publisher'] ?? '-',
                'state' => $record['state'] ?? 'not_installed', 'revision' => $record['revision'] ?? 0,
                'available' => $package !== null, 'requires_upgrade' => $changed,
                'diagnostic' => $failures[$id]['message'] ?? ($changed ? '插件文件有更新，请审核后更新安装记录' : ''),
                'configuration_scope' => $package['configuration_scope'] ?? null,
                'configuration_fields' => $package['configuration_fields'] ?? [],
                'capabilities' => $package['capabilities'] ?? [], 'created_at' => $record['created_at'] ?? null];
        }
        return $result;
    }

    /**
     * 执行固定生命周期动作；HTTP 授权由后台门面完成，CLI 属受控维护入口。
     * @param string $id 固定插件标识
     * @param string $action 固定动作
     * @param int $revision 安装记录版本
     * @param int|null $actorId 管理员，CLI 为 null
     * @param array{client_ip: string, client_ip_location: string}|null $origin HTTP 来源
     * @return array<string, mixed> 新安装状态
     * @throws BusinessException 包、引用或状态不符合要求
     */
    public function transition(string $id, string $action, int $revision, ?int $actorId = null, ?array $origin = null): array
    {
        $package = $this->packages->packages()[$id] ?? null;
        if ($package === null && !in_array($action, ['drain', 'disable', 'uninstall'], true)) {
            throw new BusinessException('插件不可用，请检查本地安装包及兼容性', ApiErrorCode::CONFLICT, 409);
        }
        $package ??= $this->installations->all()[$id] ?? throw new BusinessException('插件尚未安装', ApiErrorCode::NOT_FOUND, 404);
        $result = $this->installations->transition($package, $action, $revision, $actorId, $origin);
        return array_intersect_key($result, array_flip(['id', 'category', 'version', 'state', 'revision', 'created_at', 'updated_at']));
    }
}
