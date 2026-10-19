<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure;

use app\exception\BusinessException;
use app\modules\service_instance\contract\UndeliveredRefundGuardInterface;
use app\modules\service_instance\infrastructure\model\{ProvisioningTaskModel, ServiceInstanceModel};
use app\shared\http\ApiErrorCode;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** 与开通领取、结果提交和人工重试共用订单锁，拒绝以终止状态代替未创建证据。 */
final class DatabaseUndeliveredRefundGuard implements UndeliveredRefundGuardInterface
{
    /** @inheritDoc */
    public function lockEvidence(int $orderId, int $unitId, ?int $serviceId): array
    {
        if ((new ProvisioningTaskModel())->getConnection()->transactionLevel() < 1) {
            throw new LogicException('交付证据核验必须在订单事务内执行');
        }
        $task = $this->candidates()->where('order_id', $orderId)->where('order_unit_id', $unitId)
            ->where('service_id', $serviceId)->lockForUpdate()->first();
        $service = $serviceId === null ? null : ServiceInstanceModel::query()->whereKey($serviceId)
            ->where('origin_order_unit_id', $unitId)->lockForUpdate()->first();
        if ($task === null || $service === null || $service->activated_at !== null || $service->status !== 'terminated') {
            throw new BusinessException('仅确认未创建的未交付实例可退款；开通结果未知、已交付或缺少证据时请先核查', ApiErrorCode::CONFLICT, 409);
        }
        return ['task_id' => (int) $task->id, 'evidence' => $task->error_code === 'operator_confirmed_not_created'
            ? '人工核实未创建：' . $task->not_created_reference
            : '供应明确失败且未创建：开通任务 ' . $task->id];
    }

    /** @inheritDoc */
    public function eligibleUnits(array $unitIds): array
    {
        return $this->candidates()->whereIn('order_unit_id', $unitIds)
            ->whereHas('refundService', static function (Builder $query): void {
                $query->where('status', 'terminated')->whereNull('activated_at');
            })->pluck('order_unit_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return Builder<ProvisioningTaskModel> 失败且无外部资源，人工核查必须有完整凭证 */
    private function candidates(): Builder
    {
        return ProvisioningTaskModel::query()->where('status', 'failed')->whereNull('external_id')->whereNull('lease_token')
            ->where(function (Builder $query): void {
                $query->where('error_code', 'provider_rejected')->orWhere(function (Builder $manual): void {
                    $manual->where('error_code', 'operator_confirmed_not_created')->whereNotNull('not_created_by')
                        ->whereNotNull('not_created_at')->whereNotNull('not_created_reference')->where('not_created_reference', '<>', '')
                        ->whereNotNull('not_created_reason')->where('not_created_reason', '<>', '');
                });
            });
    }
}
