<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure;

use app\exception\BusinessException;
use app\modules\service_instance\contract\InstanceOperationRepositoryInterface;
use app\modules\service_instance\infrastructure\model\{LifecycleTaskModel as Task, ServiceInstanceModel as Service};
use app\shared\http\ApiErrorCode;

/** 所有写入由应用服务持有服务行锁，和续费、到期互斥。 */
final class DatabaseInstanceOperationRepository implements InstanceOperationRepositoryInterface
{
    public const array ACTIONS = ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'];

    /** @inheritDoc */
    public function history(int $id): array
    {
        return Task::query()->where('service_id', $id)->whereIn('action', self::ACTIONS)->orderByDesc('id')->limit(20)
            ->get(['id', 'action', 'status', 'error_code', 'created_at', 'updated_at'])->map(static fn (Task $t): array => $t->getAttributes())->all();
    }

    /** @inheritDoc */
    public function busy(int $id): bool
    {
        return Task::query()->where('service_id', $id)->whereIn('status', ['queued', 'processing', 'waiting', 'attention'])->exists();
    }

    /** @inheritDoc */
    public function existing(int $id, string $key): ?array
    {
        $task = Task::query()->where('service_id', $id)->where('request_key', $key)->first();
        return $task ? array_replace($task->getAttributes(), ['operation_context' => $task->operation_context ?? []]) : null;
    }

    /** @inheritDoc */
    public function enqueue(array $service, array $values): array
    {
        $pending = $this->busy($service['id']);
        if ($pending) {
            throw new BusinessException('实例已有未完成任务，请等待或联系管理员核查', ApiErrorCode::CONFLICT, 409);
        }
        $task = Task::query()->create($values + ['service_id' => $service['id'], 'action' => '', 'status' => 'queued',
            'expected_expires_at' => $service['expires_at'], 'target_expires_at' => $service['expires_at'], 'months' => 0,
            'next_attempt_at' => date('Y-m-d H:i:s')]);
        if (in_array($task->action, ['reinstall', 'reset_password'], true)) {
            Service::query()->whereKey($service['id'])->update(['initial_password_disabled_at' => date('Y-m-d H:i:s')]);
        }
        return ['id' => $task->id, 'status' => $task->status];
    }
}
