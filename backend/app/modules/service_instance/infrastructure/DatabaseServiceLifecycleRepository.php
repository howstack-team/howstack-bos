<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure;

use app\exception\BusinessException;
use app\modules\service_instance\contract\ServiceLifecycleRepositoryInterface;
use app\modules\service_instance\domain\ProvisioningSchedule;
use app\modules\service_instance\infrastructure\model\{LifecycleTaskModel as Task, ServiceInstanceModel as Service, ProvisioningTaskModel as Provision};
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\provider\LifecycleResult;

/** 服务行锁串行化续费、到期、恢复及实例运维，任务提交意图和租约先于外部调用落库。 */
final class DatabaseServiceLifecycleRepository implements ServiceLifecycleRepositoryInterface
{
    /** @inheritDoc */
    public function service(int $id, bool $lock = false): array
    {
        $query = Service::query()->whereKey($id);
        $service = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$service) {
            throw new BusinessException('服务不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        $source = Provision::query()->where('service_id', $id)->first();
        return $service->getAttributes() + ['source' => $source ? array_replace($source->getAttributes(), ['context' => $source->context]) : null];
    }

    /** @inheritDoc */
    public function renewals(int $serviceId): array
    {
        return Task::query()->where('service_id', $serviceId)->where('action', 'renew')
            ->whereIn('status', ['unpaid', 'queued', 'processing', 'waiting', 'attention'])->orderBy('id')->get()
            ->map(static fn (Task $task): array => $task->getAttributes())->all();
    }

    /** @inheritDoc */
    public function reserve(int $serviceId, int $orderId, int $months, string $expiry): void
    {
        if (Task::query()->where('service_id', $serviceId)->where('action', 'resume')->whereNotIn('status', ['succeeded', 'cancelled'])->exists()) {
            self::conflict('实例恢复尚未结束，请处理后再续费');
        }
        Task::query()->create(['service_id' => $serviceId, 'order_id' => $orderId, 'action' => 'renew',
            'request_key' => 'renew-' . bin2hex(random_bytes(16)), 'months' => $months, 'expected_expires_at' => $expiry,
            'target_expires_at' => $expiry, 'status' => 'unpaid', 'next_attempt_at' => date('Y-m-d H:i:s')]);
    }

    /** @inheritDoc */
    public function paid(int $orderId): void
    {
        $task = Task::query()->where('order_id', $orderId)->first();
        if (!$task) {
            self::conflict('续费任务不存在，不能付款');
        }
        $service = $this->service($task->service_id, true);
        $task = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
        if ($task->status !== 'unpaid') {
            return;
        }
        if (!in_array($service['status'], ['active', 'expired'], true) || $service['expires_at'] !== $task->expected_expires_at) {
            self::conflict('实例状态或到期时间已变化，请取消订单后重新续费');
        }
        $start = new \DateTimeImmutable(max($service['expires_at'], date('Y-m-d H:i:s')));
        $target = (new ProvisioningSchedule())->expiry($start, $task->months)->format('Y-m-d H:i:s');
        $task->fill(['status' => 'queued', 'target_expires_at' => $target, 'next_attempt_at' => date('Y-m-d H:i:s')])->save();
    }

    /** @inheritDoc */
    public function expired(int $limit): array
    {
        return Service::query()->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<=', date('Y-m-d H:i:s'))
            ->whereHas('provisioningTask', static fn ($q) => $q->where('status', 'succeeded'))
            ->orderBy('id')->limit($limit)->pluck('id')->all();
    }

    /** @inheritDoc */
    public function expire(int $serviceId): bool
    {
        $service = $this->service($serviceId, true);
        if ($service['status'] !== 'active' || !$service['expires_at'] || $service['expires_at'] > date('Y-m-d H:i:s')) {
            return false;
        }
        Service::query()->whereKey($serviceId)->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
        if (Task::query()->where('service_id', $serviceId)->where('action', 'renew')->whereIn('status', ['queued', 'processing', 'waiting', 'attention'])->exists()) {
            return true;
        }
        $this->systemTask($serviceId, 'suspend', $service['expires_at']);
        return true;
    }

    /** @inheritDoc */
    public function reminders(int $limit): array
    {
        $result = [];
        foreach ([7 => 3, 3 => 1, 1 => 0] as $days => $lower) {
            $ids = Service::query()->where('status', 'active')
                ->where('expires_at', '>', date('Y-m-d H:i:s', time() + $lower * 86400))
                ->where('expires_at', '<=', date('Y-m-d H:i:s', time() + $days * 86400))
                ->whereDoesntHave('lifecycleTasks', static function ($q) use ($days): void {
                    $q->where('action', 'notice' . $days)->whereColumn('expected_expires_at', 'service_instances.expires_at');
                })->orderBy('id')->limit($limit)->pluck('id')->all();
            foreach ($ids as $id) {
                $result[] = ['service_id' => $id, 'days' => $days];
            }
        }
        return $result;
    }

    /** @inheritDoc */
    public function recordReminder(int $id, int $days): ?array
    {
        $service = $this->service($id, true);
        if ($service['status'] !== 'active' || !$service['expires_at'] || $service['expires_at'] <= date('Y-m-d H:i:s')
            || $service['expires_at'] > date('Y-m-d H:i:s', time() + $days * 86400)) {
            return null;
        }
        $task = Task::query()->firstOrCreate(['service_id' => $id, 'action' => 'notice' . $days, 'expected_expires_at' => $service['expires_at']],
            ['request_key' => 'notice-' . bin2hex(random_bytes(16)), 'target_expires_at' => $service['expires_at'], 'months' => 0,
                'status' => 'succeeded', 'next_attempt_at' => date('Y-m-d H:i:s')]);
        return $task->wasRecentlyCreated ? $service : null;
    }

    /** @inheritDoc */
    public function due(int $limit): array
    {
        Task::query()->whereNotNull('password_encrypted')->where('credential_expires_at', '<=', date('Y-m-d H:i:s'))
            ->update(['password_encrypted' => null]);
        return Task::query()->where(function ($q): void {
            $q->whereIn('status', ['queued', 'waiting'])->where('next_attempt_at', '<=', date('Y-m-d H:i:s'))
                ->orWhere(function ($q): void {
                    $q->where('status', 'processing')->where('lease_until', '<=', date('Y-m-d H:i:s'));
                });
        })->orderBy('id')->limit($limit)->get(['id', 'service_id', 'order_id'])->map(static fn (Task $t): array => $t->getAttributes())->all();
    }

    /** @inheritDoc */
    public function claim(int $id): ?array
    {
        $candidate = Task::query()->find($id);
        if (!$candidate) {
            return null;
        }
        $service = $this->service($candidate->service_id, true);
        $task = Task::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $now = date('Y-m-d H:i:s');
        if (!in_array($task->status, ['queued', 'waiting', 'processing'], true)
            || ($task->status === 'processing' ? $task->lease_until > $now : $task->next_attempt_at > $now)) {
            return null;
        }
        // 已提交但结果未知的其他动作必须先查清；不同动作不能同时操作同一台资源。
        if (Task::query()->where('service_id', $task->service_id)->where('id', '<>', $task->id)
            ->where(static function ($q): void {
                $q->where('status', 'processing')->orWhere(static function ($pending): void {
                    $pending->whereIn('status', ['waiting', 'attention'])->whereNotNull('submitted_at');
                });
            })->exists()) {
            // 让出本批次，避免少数互斥实例永久占据全局队列前几条。
            $task->fill(['status' => 'waiting', 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => date('Y-m-d H:i:s', time() + 15)])->save();
            return null;
        }
        if (!in_array($service['status'], ['active', 'expired'], true) || !$service['source'] || $service['source']['status'] !== 'succeeded') {
            $task->fill(['status' => 'attention', 'error_code' => 'service_state_conflict'])->save();
            return null;
        }
        if ($task->action === 'suspend' && $task->submitted_at === null) {
            if ($service['expires_at'] !== $task->expected_expires_at || $service['expires_at'] > $now
                || Task::query()->where('service_id', $task->service_id)->where('action', 'renew')->whereIn('status', ['queued', 'processing', 'waiting', 'attention'])->exists()) {
                $task->fill(['status' => 'cancelled', 'error_code' => null])->save();
                return null;
            }
        }
        if ($task->action === 'resume' && ($service['expires_at'] !== $task->expected_expires_at || $service['expires_at'] <= $now)) {
            $task->fill(['status' => 'cancelled'])->save();
            $this->systemTask($service['id'], 'suspend', $service['expires_at']);
            return null;
        }
        if (in_array($task->action, ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'], true) && $task->submitted_at === null) {
            if ($service['status'] !== 'active' || $service['expires_at'] <= $now) {
                $task->fill(['status' => 'cancelled', 'password_encrypted' => null, 'error_code' => 'service_expired'])->save();
                return null;
            }
            if (in_array($task->action, ['reinstall', 'reset_password'], true) && (!$task->password_encrypted || $task->credential_expires_at <= $now)) {
                $task->fill(['status' => 'failed', 'password_encrypted' => null, 'error_code' => 'credential_unavailable'])->save();
                return null;
            }
        }
        $queryOnly = $task->submitted_at !== null;
        $task->fill(['status' => 'processing', 'attempts' => $task->attempts + 1,
            'lease_token' => bin2hex(random_bytes(24)), 'lease_until' => date('Y-m-d H:i:s', time() + 180)])->save();
        return array_replace($task->getAttributes(), ['operation_context' => $task->operation_context ?? []]) + ['query_only' => $queryOnly, 'source' => $service['source']];
    }

    /**
     * 在供应能力预检通过后记录提交意图；能力缺失不能误判为已经向供应提交。
     * @param array<string,mixed> $task 当前租约
     * @return bool 仍持有租约
     */
    public function submitted(array $task): bool
    {
        $query = Task::query()->whereKey($task['id'])->where('status', 'processing')->where('lease_token', $task['lease_token'])
            ->where('lease_until', '>', date('Y-m-d H:i:s'));
        if ($task['submitted_at'] !== null) {
            return $query->exists();
        }
        return $query->update(['submitted_at' => date('Y-m-d H:i:s'), 'password_encrypted' => null]) === 1;
    }

    /** @inheritDoc */
    public function finish(array $task, LifecycleResult $result, ?string $error): bool
    {
        $service = $this->service($task['service_id'], true);
        $row = Task::query()->whereKey($task['id'])->lockForUpdate()->firstOrFail();
        if ($row->status !== 'processing' || $row->lease_token !== $task['lease_token'] || $row->lease_until <= date('Y-m-d H:i:s')) {
            return false;
        }
        $success = $result->status === 'succeeded';
        if ($success && !in_array($service['status'], ['active', 'expired'], true)) {
            $success = false;
            $error = 'service_state_conflict';
        }
        if ($row->action === 'renew' && $success && $result->confirmedExpiresAt !== $row->target_expires_at) {
            $success = false;
            $error = 'expiry_not_confirmed';
        }
        if ($success && $row->action === 'renew') {
            if ($service['expires_at'] !== $row->expected_expires_at) {
                $success = false;
                $error = 'service_state_conflict';
            } else {
                $suspended = Task::query()->where('service_id', $row->service_id)->where('action', 'suspend')
                    ->where('status', 'succeeded')->where('expected_expires_at', $row->expected_expires_at)->exists();
                $expired = $row->target_expires_at <= date('Y-m-d H:i:s');
                Service::query()->whereKey($row->service_id)->update(['expires_at' => $row->target_expires_at,
                    'status' => $suspended || $expired ? 'expired' : 'active', 'updated_at' => date('Y-m-d H:i:s')]);
                if ($expired) {
                    $this->systemTask($row->service_id, 'suspend', $row->target_expires_at);
                } elseif ($suspended) {
                    $this->systemTask($row->service_id, 'resume', $row->target_expires_at);
                }
            }
        } elseif ($success && $row->action === 'resume') {
            $expired = $service['expires_at'] <= date('Y-m-d H:i:s');
            Service::query()->whereKey($row->service_id)->update(['status' => $expired ? 'expired' : 'active', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($expired) {
                $this->systemTask($row->service_id, 'suspend', $service['expires_at']);
            }
        }
        $operation = in_array($row->action, ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'], true);
        if ($result->providerTaskId !== null) {
            if ($row->provider_task_id !== null && $row->provider_task_id !== $result->providerTaskId) {
                $success = false;
                $error = 'external_identity_conflict';
            } else {
                $row->provider_task_id = $result->providerTaskId;
            }
        }
        if ($operation && $success) {
            $values = ['updated_at' => date('Y-m-d H:i:s')];
            if (in_array($result->powerStatus, ['on', 'off'], true)) {
                $values += ['power_status' => $result->powerStatus, 'power_checked_at' => date('Y-m-d H:i:s')];
            } else {
                $values += ['power_status' => 'unknown', 'power_checked_at' => date('Y-m-d H:i:s')];
            }
            if ($row->action === 'reinstall') {
                $image = $row->operation_context['image'];
                unset($image['external_id']);
                $values['installed_image'] = json_encode($image, JSON_THROW_ON_ERROR);
            }
            Service::query()->whereKey($row->service_id)->update($values);
        }
        $attention = $row->attempts >= 20 || $result->status === 'failed'
            || in_array($error, ['adapter_changed', 'lifecycle_unsupported', 'service_state_conflict', 'expiry_not_confirmed', 'operation_unsupported', 'credential_unavailable', 'external_identity_conflict'], true);
        $row->fill(['status' => $success ? 'succeeded' : ($operation && $result->status === 'failed' ? 'failed' : ($attention ? 'attention' : 'waiting')), 'error_code' => $success ? null : ($error ?? ($result->status === 'failed' ? 'provider_operation_failed' : 'provider_result_unknown')),
            'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => date('Y-m-d H:i:s', time() + min(300, 10 * (2 ** min(5, $row->attempts))))])->save();
        return $success;
    }

    /** @inheritDoc */
    public function retry(int $serviceId): void
    {
        $this->service($serviceId, true);
        $task = Task::query()->where('service_id', $serviceId)->where('status', 'attention')->orderBy('id')->lockForUpdate()->first();
        if (!$task) {
            self::conflict('当前没有需要人工重试的任务');
        }
        $task->fill(['status' => 'queued', 'attempts' => 0, 'error_code' => null, 'next_attempt_at' => date('Y-m-d H:i:s')])->save();
    }

    /**
     * @param int $serviceId 服务
     * @param string $action 系统动作
     * @param string $expiry 当前到期时间
     * @return void 同服务同到期时间只建立一次
     */
    private function systemTask(int $serviceId, string $action, string $expiry): void
    {
        Task::query()->firstOrCreate(['service_id' => $serviceId, 'action' => $action, 'expected_expires_at' => $expiry],
            ['request_key' => $action . '-' . bin2hex(random_bytes(16)), 'target_expires_at' => $expiry, 'months' => 0,
                'status' => 'queued', 'next_attempt_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @param string $message 可公开冲突原因
     * @return never
     */
    private static function conflict(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
