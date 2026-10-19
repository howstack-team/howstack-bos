<?php

declare(strict_types=1);

namespace app\modules\order\application;

use app\modules\order\contract\{OrderProvisioningInterface, OrderProvisioningRepositoryInterface};
use app\modules\service_instance\application\{ProvisionPaidOrderService, ProcessServiceLifecycleService};

/** 按交易语义分派履约，不按产品身份分支；续费不会再次开通或占库。 */
final readonly class DispatchPaidOrderService implements OrderProvisioningInterface
{
    /**
     * @param OrderProvisioningRepositoryInterface $orders 订单事实
     * @param ProvisionPaidOrderService $purchase 首次开通
     * @param ProcessServiceLifecycleService $renewal 存量续期
     */
    public function __construct(private OrderProvisioningRepositoryInterface $orders, private ProvisionPaidOrderService $purchase, private ProcessServiceLifecycleService $renewal)
    {
    }

    /** @inheritDoc */
    public function prepare(int $orderId, #[\SensitiveParameter] ?string $password): void
    {
        $this->purchase->prepare($orderId, $password);
    }

    /** @inheritDoc */
    public function enqueue(int $orderId): void
    {
        $order = $this->orders->lock($orderId);
        if ($order['order_type'] === 'renewal') {
            $this->renewal->paid($orderId);
        } else {
            $this->purchase->enqueue($orderId);
        }
    }
}
