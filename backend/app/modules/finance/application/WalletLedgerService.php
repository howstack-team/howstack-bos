<?php

declare(strict_types=1);

namespace app\modules\finance\application;

use app\exception\BusinessException;
use app\modules\finance\contract\WalletLedgerRepositoryInterface;
use app\modules\finance\domain\WalletOrderPayment;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

/** 公开资金门面，负责支付匹配及来源批次扣减规则，不接触订单持久化。 */
final readonly class WalletLedgerService
{
    public function __construct(private WalletLedgerRepositoryInterface $ledger, private TransactionManagerInterface $transactions,
        private \app\modules\message\contract\MessagePublisherInterface $messages)
    {
    }

    /** @return array{currency: string, available_minor: int, frozen_minor: int} */
    public function balance(int $memberId): array
    {
        return $this->ledger->balance($memberId);
    }

    /** 已验签支付确认后的充值入账；完整匹配支付记录且最多入账一次。 */
    public function creditConfirmedRecharge(int $memberId, int $rechargeId): int
    {
        return $this->transactions->run(function () use ($memberId, $rechargeId): int {
            $recharge = $this->ledger->lockRecharge($rechargeId);
            if ($recharge === null || $recharge['member_id'] !== $memberId) throw self::conflict('充值记录不存在或不属于当前会员');
            if (!in_array($recharge['status'], ['paid', 'credited'], true)) throw self::conflict('充值尚未确认支付成功');
            $payments = $this->ledger->confirmedRechargePayments($rechargeId);
            if (count($payments) !== 1 || $payments[0] !== [
                'member_id' => $memberId, 'amount_minor' => $recharge['amount_minor'],
                'currency' => $recharge['currency'], 'channel' => $recharge['channel'],
            ]) throw self::conflict('充值支付交易尚未核实');
            $account = $this->ledger->lockAccount($memberId, $recharge['currency']);
            if ($recharge['status'] === 'credited') return $account['available_minor'];
            $amount = $recharge['amount_minor'];
            if ($amount <= 0 || $amount > PHP_INT_MAX - $account['available_minor']) throw self::conflict('充值金额无效或余额超出范围');
            $balance = $account['available_minor'] + $amount;
            $this->ledger->recordCredit($account, $rechargeId, $amount, $balance);
            $this->messages->publish('recharge.credited.' . $rechargeId, 'recharge_received', 'recharge', $rechargeId, $memberId,
                ['site_name' => '云业务运营系统', 'amount' => intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT)]);
            return $balance;
        });
    }

    /** 已付款订单重试只核对资金支付记录，不重复扣款。 */
    public function confirmedOrderBalance(WalletOrderPayment $payment): int
    {
        return $this->transactions->run(function () use ($payment): int {
            $account = $this->ledger->lockAccount($payment->memberId, $payment->currency);
            $previous = $this->ledger->orderWalletPayment($payment->orderId);
            if ($previous === null || $previous['status'] !== 'paid' || $previous['member_id'] !== $payment->memberId
                || $previous['amount_minor'] !== $payment->amountMinor || $previous['currency'] !== $payment->currency) {
                throw self::conflict('订单已通过其他方式付款或资金记录不匹配');
            }
            return $account['available_minor'];
        });
    }

    /** 订单用例先持有订单锁；本门面只执行资金操作，嵌套事务仍属于同一连接。 */
    public function debitOrder(WalletOrderPayment $payment): int
    {
        return $this->transactions->run(function () use ($payment): int {
            if ($payment->amountMinor <= 0 || !preg_match('/^[A-Z]{3}$/', $payment->currency)) throw self::conflict('付款金额或币种无效');
            $account = $this->ledger->lockAccount($payment->memberId, $payment->currency);
            if ($this->ledger->orderWalletPayment($payment->orderId) !== null) throw self::conflict('订单资金支付记录已经存在');
            if ($account['available_minor'] < $payment->amountMinor) throw self::conflict('余额不足');
            $remaining = $payment->amountMinor;
            $allocations = [];
            foreach ($this->ledger->lockFundingLots($account['id']) as $lot) {
                if ($remaining === 0) break;
                $used = min($remaining, $lot['remaining_minor']);
                $allocations[] = ['id' => $lot['id'], 'remaining_minor' => $lot['remaining_minor'] - $used, 'consumed_minor' => $used];
                $remaining -= $used;
            }
            if ($remaining !== 0) throw self::conflict('充值来源批次余额不足，需核对资金记录');
            $balance = $account['available_minor'] - $payment->amountMinor;
            $this->ledger->recordDebit($account, $payment, $allocations, $balance);
            return $balance;
        });
    }

    private static function conflict(string $message): BusinessException
    {
        return new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
