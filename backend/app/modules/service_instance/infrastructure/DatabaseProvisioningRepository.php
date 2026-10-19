<?php

declare(strict_types=1);
namespace app\modules\service_instance\infrastructure;

use app\exception\BusinessException;
use app\modules\service_instance\contract\ProvisioningRepositoryInterface;
use app\modules\service_instance\domain\ProvisioningSchedule;
use app\modules\service_instance\infrastructure\model\{ProvisioningTaskModel as Task, ProvisioningCredentialModel as Credential, ServiceInstanceModel as Service};
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\provider\ProvisionResult;

/** 订单锁先于任务锁；租约先落库后调用网络，回写须核验令牌。 */
final readonly class DatabaseProvisioningRepository implements ProvisioningRepositoryInterface
{
    /** @param SensitiveDataCipherInterface $cipher 独立敏感信息加密器 */
    public function __construct(private SensitiveDataCipherInterface $cipher) {}
    /** @inheritDoc */
    public function prepare(int $orderId, #[\SensitiveParameter] string $password): void
    {
        Credential::query()->firstOrCreate(['order_id' => $orderId], ['password_encrypted' => $this->cipher->encrypt($password), 'created_at' => date('Y-m-d H:i:s')]);
    }
    /** @inheritDoc */
    public function password(int $orderId): string
    {
        $row = Credential::query()->where('order_id', $orderId)->first();
        if (!$row || !$row->password_encrypted || ($row->expires_at !== null && $row->expires_at <= date('Y-m-d H:i:s'))) {
            throw new BusinessException('初始密码已清理，请使用实例密码重置功能', ApiErrorCode::CONFLICT, 409);
        }
        return $this->cipher->decrypt($row->password_encrypted);
    }
    /** @inheritDoc */
    public function create(array $order, array $unit, array $capture): int
    {
        $existing = Task::query()->where('order_unit_id', $unit['id'])->first();
        if ($existing) return $existing->service_id;
        $key = 'bos-' . bin2hex(random_bytes(16));
        $service = Service::query()->create(['instance_no' => $key, 'product_type' => $unit['product_type'], 'member_id' => $order['member_id'],
            'display_name' => $unit['product_name'] . ' ' . $unit['unit_index'], 'status' => 'provisioning', 'origin_order_unit_id' => $unit['id']]);
        $context = $unit['snapshot'];
        $context['provisioning']['quantity'] = 1;
        Task::query()->create(['order_id' => $order['id'], 'order_unit_id' => $unit['id'], 'service_id' => $service->id, 'request_key' => $key,
            'status' => isset($capture['error_code']) ? 'attention' : 'queued', 'next_attempt_at' => date('Y-m-d H:i:s'), 'context' => $context] + $capture);
        return $service->id;
    }
    /** @inheritDoc */
    public function forOrder(int $orderId): array
    {
        return Task::query()->where('order_id', $orderId)->orderBy('id')->get()->map(fn (Task $t): array => $t->getAttributes() + [])->all();
    }
    /** @inheritDoc */
    public function due(int $limit): array
    {
        return Task::query()->where(function ($q): void {
            $q->whereIn('status', ['queued', 'waiting'])->where('next_attempt_at', '<=', date('Y-m-d H:i:s'))
                ->orWhere(function ($q): void { $q->where('status', 'processing')->where('lease_until', '<=', date('Y-m-d H:i:s')); });
        })->orderBy('id')->limit($limit)->get(['id', 'order_id'])->map(fn (Task $t): array => $t->getAttributes())->all();
    }
    /** @inheritDoc */
    public function claim(int $id): ?array
    {
        $task = Task::query()->whereKey($id)->lockForUpdate()->first();
        if (!$task || !in_array($task->status, ['queued', 'waiting', 'processing'], true)
            || ($task->status === 'processing' && $task->lease_until > date('Y-m-d H:i:s'))
            || ($task->status !== 'processing' && $task->next_attempt_at > date('Y-m-d H:i:s'))) return null;
        $create = $task->submitted_at === null;
        $task->fill(['status' => 'processing', 'attempts' => $task->attempts + 1, 'lease_token' => bin2hex(random_bytes(24)),
            'lease_until' => date('Y-m-d H:i:s', time() + 180), 'submitted_at' => $task->submitted_at ?? date('Y-m-d H:i:s')])->save();
        return $this->map($task) + ['operation' => $create ? 'create' : 'query'];
    }
    /** @inheritDoc */
    public function finish(array $task, ProvisionResult $result, ?string $error = null): bool
    {
        $row = Task::query()->whereKey($task['id'])->lockForUpdate()->firstOrFail();
        if ($row->status !== 'processing' || $row->lease_token !== $task['lease_token'] || $row->lease_until <= date('Y-m-d H:i:s')) return false;
        if ($row->external_id !== null && $result->externalId !== null && $row->external_id !== $result->externalId) {
            $result = new ProvisionResult('unknown'); $error = 'external_identity_conflict';
        }
        if ($row->external_id !== null && $result->status === 'failed') { $result = new ProvisionResult('unknown'); $error = 'resource_requires_review'; }
        $status = match ($result->status) { 'active' => 'succeeded', 'failed' => 'failed', default => $row->attempts >= 20 ? 'attention' : 'waiting' };
        if (in_array($error, ['adapter_changed', 'provider_unavailable', 'credential_unavailable', 'external_identity_conflict', 'resource_requires_review'], true)) $status = 'attention';
        $row->fill(['status' => $status, 'external_id' => $row->external_id ?? $result->externalId,
            'addresses' => $result->addresses ?: ($row->addresses ?? []), 'lease_token' => null, 'lease_until' => null,
            'error_code' => $result->status === 'active' ? null : ($error ?? ($result->status === 'failed' ? 'provider_rejected' : 'awaiting_provider')),
            'next_attempt_at' => date('Y-m-d H:i:s', time() + min(300, 10 * (2 ** min(5, $row->attempts))))])->save();
        $service = Service::query()->whereKey($row->service_id)->lockForUpdate()->firstOrFail();
        if ($status === 'succeeded') {
            $start = new \DateTimeImmutable();
            $service->fill(['status' => 'active', 'activated_at' => $start->format('Y-m-d H:i:s'),
                'expires_at' => (new ProvisioningSchedule())->expiry($start, (int) $row->context['configuration']['duration_months'])->format('Y-m-d H:i:s')]);
        } elseif ($status === 'failed') {
            // 仅供应明确确认没有创建资源才允许财务退款；未知结果仍为开通中。
            $service->status = 'terminated';
        }
        $service->save();
        if (!Task::query()->where('order_id', $row->order_id)->whereNotIn('status', ['succeeded', 'failed'])->exists()) {
            Credential::query()->where('order_id', $row->order_id)->update(['expires_at' => date('Y-m-d H:i:s', time() + 172800)]);
        }
        return true;
    }
    /** @inheritDoc */
    public function forService(int $serviceId): ?array
    {
        $task = Task::query()->where('service_id', $serviceId)->first();
        return $task ? $this->map($task) : null;
    }
    /** @inheritDoc */
    public function retry(int $id, array $capture): void
    {
        $task = Task::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if (!in_array($task->status, ['failed', 'attention'], true)) throw new BusinessException('当前任务无需重试', ApiErrorCode::CONFLICT, 409);
        $knownFailure = $task->status === 'failed' && $task->external_id === null;
        $task->fill($capture + ['not_created_by' => null, 'not_created_reason' => null, 'not_created_reference' => null, 'not_created_at' => null, 'status' => 'waiting', 'attempts' => 0, 'error_code' => null, 'next_attempt_at' => date('Y-m-d H:i:s'),
            'submitted_at' => $knownFailure ? null : $task->submitted_at, 'lease_token' => null, 'lease_until' => null])->save();
        Service::query()->whereKey($task->service_id)->update(['status' => 'provisioning', 'updated_at' => date('Y-m-d H:i:s')]);
        Credential::query()->where('order_id', $task->order_id)->update(['expires_at' => null]);
    }
    /** @inheritDoc */
    public function confirmNotCreated(int $id, int $actorId, string $reason, string $reference): void
    {
        $task = Task::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($task->status !== 'attention' || $task->external_id !== null) throw new BusinessException('仅结果不明且未关联供应实例的任务可确认未开通', ApiErrorCode::CONFLICT, 409);
        $task->fill(['not_created_by' => $actorId, 'not_created_reason' => $reason, 'not_created_reference' => $reference, 'not_created_at' => date('Y-m-d H:i:s'), 'status' => 'failed', 'error_code' => 'operator_confirmed_not_created', 'lease_token' => null, 'lease_until' => null])->save();
        Service::query()->whereKey($task->service_id)->update(['status' => 'terminated', 'updated_at' => date('Y-m-d H:i:s')]);
        if (!Task::query()->where('order_id', $task->order_id)->whereNotIn('status', ['succeeded', 'failed'])->exists()) Credential::query()->where('order_id', $task->order_id)->update(['expires_at' => date('Y-m-d H:i:s', time() + 172800)]);
    }
    /** @inheritDoc */
    public function cleanup(): void
    {
        $closed = \support\Db::table('orders')->whereIn('status', ['cancelled', 'refunded'])->select('id');
        Credential::query()->whereNotNull('password_encrypted')->whereIn('order_id', $closed)->update(['password_encrypted' => null]);
        Credential::query()->whereNotNull('password_encrypted')->whereNotNull('expires_at')->where('expires_at', '<=', date('Y-m-d H:i:s'))->update(['password_encrypted' => null]);
    }
    /** @param Task $task 持久模型 @return array<string,mixed> 只传给内部执行层 */
    private function map(Task $task): array
    {
        return array_replace($task->getAttributes(), ['context' => $task->context, 'addresses' => $task->addresses ?? []]);
    }
}
