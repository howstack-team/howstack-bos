<?php

declare(strict_types=1);

namespace app\modules\order\application;

use app\exception\BusinessException;
use app\modules\order\contract\OrderManagementRepositoryInterface;
use app\modules\message\contract\MessagePublisherInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

/** 取消本人未付订单，释放后续续费的逻辑占位，不改变任何服务或资金。 */
final readonly class CancelMemberOrderService
{
    /**
     * @param OrderManagementRepositoryInterface $orders 订单锁与修改
     * @param TransactionManagerInterface $transactions 本地事务
     * @param MessagePublisherInterface $messages 同事务通知
     */
    public function __construct(private OrderManagementRepositoryInterface $orders, private TransactionManagerInterface $transactions, private MessagePublisherInterface $messages)
    {
    }

    /**
     * @param int $memberId 认证本人
     * @param int $id 订单
     * @return void 可重复取消，无权及已付订单均拒绝
     */
    public function cancel(int $memberId, int $id): void
    {
        $this->transactions->run(function () use ($memberId, $id): void {
            $order = $this->orders->lock($id);
            if ($order['member_id'] !== $memberId) {
                throw new BusinessException('订单不存在', ApiErrorCode::NOT_FOUND, 404);
            }
            if ($order['status'] === 'cancelled' && $order['payment_status'] === 'unpaid' && $order['paid_minor'] === 0) {
                return;
            }
            if ($order['status'] !== 'pending_payment' || $order['payment_status'] !== 'unpaid' || $order['paid_minor'] !== 0) {
                throw new BusinessException('仅未支付订单可以取消', ApiErrorCode::CONFLICT, 409);
            }
            $this->orders->cancel($id);
            $this->messages->publish('order.cancelled.' . $id, 'order_cancelled', 'order', $id, $memberId, ['order_no' => $order['order_no']]);
        });
    }
}
