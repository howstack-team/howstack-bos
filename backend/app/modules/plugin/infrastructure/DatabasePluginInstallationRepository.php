<?php

declare(strict_types=1);

namespace app\modules\plugin\infrastructure;

use app\exception\BusinessException;
use app\modules\plugin\contract\{PluginInstallationRepositoryInterface, PluginUsageInspectorInterface};
use app\modules\plugin\infrastructure\model\{PluginInstallationModel, PluginEventModel};
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

/** 固定生命周期状态机；通过统一事务与供应写入锁避免竞态。 */
final readonly class DatabasePluginInstallationRepository implements PluginInstallationRepositoryInterface
{
    /**
     * 只依赖公开引用查询与事务门面。
     * @param TransactionManagerInterface $transactions 默认连接的事务
     * @param list<PluginUsageInspectorInterface> $inspectors 各域的只读引用查询
     */
    public function __construct(private TransactionManagerInterface $transactions, private array $inspectors = []) {}

    /** @inheritDoc */
    public function all(): array
    {
        return PluginInstallationModel::query()->get()->mapWithKeys(fn (PluginInstallationModel $row): array => [$row->plugin_id => $this->profile($row)])->all();
    }

    /** @inheritDoc */
    public function transition(array $package, string $action, int $revision, ?int $actorId, ?array $origin = null): array
    {
        return $this->transactions->run(function () use ($package, $action, $revision, $actorId, $origin): array {
            $row = PluginInstallationModel::query()->where('plugin_id', $package['id'])->lockForUpdate()->first();
            $before = $row?->state;
            if (($row?->revision ?? 0) !== $revision) {
                throw new BusinessException('插件状态已变化，请刷新后重试', ApiErrorCode::CONFLICT, 409);
            }
            if ($row !== null && $row->category !== $package['category']) {
                throw new BusinessException('不能改变已安装插件的类别', ApiErrorCode::CONFLICT, 409);
            }
            $states = [
                'install' => [null, 'uninstalled'], 'enable' => ['installed', 'disabled'],
                'drain' => ['enabled'], 'disable' => ['enabled', 'draining', 'installed'],
                'upgrade' => ['installed', 'disabled', 'draining'], 'uninstall' => ['installed', 'disabled', 'draining'],
            ];
            if (!isset($states[$action]) || !in_array($before, $states[$action], true)) {
                throw new BusinessException('当前状态不能执行此操作', ApiErrorCode::CONFLICT, 409);
            }
            $usage = ['references' => 0, 'active_references' => 0];
            foreach ($this->inspectors as $inspector) {
                $counts = $inspector->inspect($package['id']);
                foreach ($usage as $key => $value) {
                    $usage[$key] = $value + $counts[$key];
                }
            }
            if ($action === 'uninstall' && $usage['references'] > 0) {
                throw new BusinessException('插件仍被资源供应商、通道配置或历史业务引用，不能卸载', ApiErrorCode::CONFLICT, 409);
            }
            if (in_array($action, ['disable', 'upgrade'], true) && $usage['active_references'] > 0) {
                throw new BusinessException('请先停止接单并禁用使用该插件的供应商或通道，完成收尾后再操作', ApiErrorCode::CONFLICT, 409);
            }
            if ($action === 'enable' && $row->package_hash !== $package['package_hash']) {
                throw new BusinessException('插件文件已变化，请先审核并更新安装记录', ApiErrorCode::CONFLICT, 409);
            }
            $row ??= new PluginInstallationModel();
            $after = match ($action) {
                'install', 'upgrade' => 'installed', 'enable' => 'enabled', 'drain' => 'draining',
                'disable' => 'disabled', 'uninstall' => 'uninstalled',
            };
            $approved = in_array($action, ['install', 'upgrade'], true);
            $row->fill(['plugin_id' => $package['id'], 'category' => $package['category'],
                'version' => $approved ? $package['version'] : $row->version,
                'package_hash' => $approved ? $package['package_hash'] : $row->package_hash,
                'state' => $after, 'revision' => $revision + 1]);
            $row->save();
            PluginEventModel::query()->create(['plugin_id' => $package['id'], 'action' => $action,
                'from_state' => $before, 'to_state' => $after, 'actor_id' => $actorId,
                'client_ip' => $origin['client_ip'] ?? null, 'client_ip_location' => $origin['client_ip_location'] ?? null,
                'package_hash' => $row->package_hash, 'created_at' => date('Y-m-d H:i:s')]);
            return $this->profile($row);
        });
    }

    /** @inheritDoc */
    public function lockAccepting(string $id): void
    {
        $row = PluginInstallationModel::query()->where('plugin_id', $id)->lockForUpdate()->first();
        if ($row === null || $row->state !== 'enabled') {
            throw new BusinessException('对接插件已停止接单或尚未启用', ApiErrorCode::CONFLICT, 409);
        }
    }

    /**
     * 稳定字段与时间映射。
     * @param PluginInstallationModel $row 安装记录
     * @return array<string, mixed> 非敏感资料
     */
    private function profile(PluginInstallationModel $row): array
    {
        return ['id' => $row->plugin_id, 'category' => $row->category, 'version' => $row->version,
            'package_hash' => $row->package_hash, 'state' => $row->state, 'revision' => $row->revision,
            'created_at' => $row->created_at->format('Y-m-d H:i:s'), 'updated_at' => $row->updated_at->format('Y-m-d H:i:s')];
    }
}
