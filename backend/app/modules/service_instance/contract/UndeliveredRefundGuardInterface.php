<?php

declare(strict_types=1);

namespace app\modules\service_instance\contract;

/** 财务只取得已核实未创建的证据，不读取供应凭据或修改开通状态。 */
interface UndeliveredRefundGuardInterface
{
    /**
     * 核查可退资格；调用方必须先锁订单，未知、曾交付或任务缺失均拒绝。
     * @param int $orderId 已锁定的订单内部ID
     * @param int $unitId 该订单的实例金额单位ID
     * @param int|null $serviceId 单位实际关联的首次服务ID
     * @return array{task_id:int,evidence:string} 可保存的核查证据摘要
     */
    public function lockEvidence(int $orderId, int $unitId, ?int $serviceId): array;

    /** @param list<int> $unitIds 候选订单单位 @return list<int> 只读候选；写入仍须重新加锁核验 */
    public function eligibleUnits(array $unitIds): array;
}
