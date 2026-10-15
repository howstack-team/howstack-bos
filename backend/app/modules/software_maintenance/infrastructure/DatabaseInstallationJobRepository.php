<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\infrastructure;

use app\exception\BusinessException;
use app\modules\software_maintenance\contract\InstallationJobRepositoryInterface;
use app\modules\software_maintenance\infrastructure\model\InstallationJobModel;
use app\shared\http\ApiErrorCode;

/** 模型写仓储；全局文件锁覆盖受理及安装，唯一UUID约束保护重复提交。 */
final class DatabaseInstallationJobRepository implements InstallationJobRepositoryInterface
{
    /** @inheritDoc */
    public function create(array $job): array
    {
        $existing = $this->find($job['request_id']);
        if ($existing !== null) {
            foreach (['kind', 'action', 'target_code', 'target_version'] as $field) {
                if ($existing[$field] !== $job[$field]) {
                    throw new BusinessException('任务标识已用于其他更新，请重新确认', ApiErrorCode::CONFLICT, 409);
                }
            }
            return $existing;
        }
        if ($this->active() !== null) {
            throw new BusinessException('已有安装或更新任务尚未结束', ApiErrorCode::CONFLICT, 409);
        }
        $now = date('Y-m-d H:i:s');
        InstallationJobModel::query()->create($job + ['status' => 'pending', 'phase' => 'pending',
            'message' => '等待执行', 'created_at' => $now, 'updated_at' => $now]);
        return $this->find($job['request_id']);
    }

    /** @inheritDoc */
    public function recent(): array
    {
        return InstallationJobModel::query()->orderByDesc('id')->limit(50)->get()->map(
            static fn (InstallationJobModel $row): array => array_intersect_key($row->getAttributes(), array_flip([
                'request_id', 'kind', 'action', 'target_code', 'from_version', 'target_version', 'status', 'phase',
                'message', 'error_code', 'report_status', 'created_at', 'updated_at',
            ])),
        )->all();
    }

    /** @inheritDoc */
    public function pendingReports(): array
    {
        return InstallationJobModel::query()->where('report_status', 'pending')
            ->whereIn('status', ['succeeded', 'failed', 'recovery_required'])->orderBy('id')->limit(10)
            ->get(['request_id', 'kind', 'status', 'error_code', 'report_status'])
            ->map(static fn (InstallationJobModel $row): array => $row->getAttributes())->all();
    }

    /** @inheritDoc */
    public function find(string $id): ?array
    {
        return InstallationJobModel::query()->where('request_id', $id)->first()?->getAttributes();
    }

    /** @inheritDoc */
    public function active(): ?array
    {
        return InstallationJobModel::query()->whereIn('status', ['pending', 'running', 'awaiting_restart', 'recovery_required'])
            ->orderBy('id')->first()?->getAttributes();
    }

    /** @inheritDoc */
    public function update(string $id, array $changes): void
    {
        $allowed = ['status', 'phase', 'message', 'error_code', 'backup_key', 'report_status', 'lease_until'];
        if (array_diff(array_keys($changes), $allowed) !== []) {
            throw new \InvalidArgumentException('维护任务字段不在写白名单内');
        }
        InstallationJobModel::query()->where('request_id', $id)->update($changes + ['updated_at' => date('Y-m-d H:i:s')]);
    }
}
