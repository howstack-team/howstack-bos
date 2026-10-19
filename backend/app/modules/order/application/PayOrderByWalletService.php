<?php

declare(strict_types=1);

namespace app\modules\order\application;

use app\exception\BusinessException;
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\domain\WalletOrderPayment;
use app\modules\order\contract\OrderPaymentRepositoryInterface;
use app\shared\contract\OutboxWriterInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

/** 订单先锁定，再调用资金门面；整个付款及 Outbox 同一事务提交。 */
final readonly class PayOrderByWalletService
{
    public function __construct(
        private OrderPaymentRepositoryInterface $orders,
        private WalletLedgerService $wallet,
        private TransactionManagerInterface $transactions,
        private OutboxWriterInterface $outbox,
        private \app\modules\message\contract\MessagePublisherInterface $messages,
        private \app\modules\product\contract\ProductOrderCapacityInterface $capacity,
        private \app\modules\order\contract\OrderProvisioningInterface $provisioning,
    ) {
    }

    /** @return int 付款后可用余额，重复付款返回当前余额 */
    public function pay(int $memberId, int $orderId): int
    {
        return $this->transactions->run(function () use ($memberId, $orderId): int {
            $order = $this->orders->lock($orderId);
            if ($order === null || $order->memberId !== $memberId) {
                throw self::conflict('订单不存在或不属于当前会员');
            }
            if (!preg_match('/^[A-Z]{3}$/', $order->currency) || $order->payableMinor <= 0) {
                throw self::conflict('订单金额或币种无效');
            }
            $payment = new WalletOrderPayment($order->id, $order->number, $memberId, $order->payableMinor, $order->currency);
            if ($order->paymentStatus === 'paid') {
                if ($order->paidMinor !== $order->payableMinor) throw self::conflict('订单实付金额异常');
                return $this->wallet->confirmedOrderBalance($payment);
            }
            if ($order->status !== 'pending_payment' || $order->paymentStatus !== 'unpaid' || $order->paidMinor !== 0) {
                throw self::conflict('订单当前状态不允许余额付款');
            }
            if (!$this->orders->allocationIsComplete($orderId, $order->payableMinor)) {
                throw self::conflict('订单实例金额尚未完整分摊，不能付款');
            }
            $balance = $this->wallet->debitOrder($payment);
            // 资金账户先于库存锁；库存不足将付款、流水与通知全部回滚。
            foreach ($this->orders->capacityItems($orderId) as $item) {
                $this->capacity->occupy($item['type'], $item['configuration'], $item['unit_ids']);
            }
            $this->orders->markPaid($orderId, $order->payableMinor, date('Y-m-d H:i:s'));
            $this->provisioning->enqueue($orderId);
            $this->outbox->append('order.paid.' . $orderId, 'order.paid', $orderId, ['order_id' => $orderId]);
            $this->messages->publish('order.paid.' . $orderId, 'order_paid', 'order', $orderId, $memberId,
                ['site_name' => '云业务运营系统', 'order_no' => $order->number,
                    'amount' => intdiv($order->payableMinor, 100) . '.' . str_pad((string) ($order->payableMinor % 100), 2, '0', STR_PAD_LEFT)]);
            return $balance;
        });
    }

    private static function conflict(string $message): BusinessException
    {
        return new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
