<?php

declare(strict_types=1);
namespace tests\support;
/** 既有财务测试隔离履约副作用；完整付款开通测试使用真实服务与数据库仓储。 */
final class RecordingOrderProvisioning implements \app\modules\order\contract\OrderProvisioningInterface
{
    public array $prepared = [];
    public array $enqueued = [];
    public function prepare(int $orderId, ?string $password): void { $this->prepared[] = $orderId; }
    public function enqueue(int $orderId): void { $this->enqueued[] = $orderId; }
}
