<?php

declare(strict_types=1);

namespace app\modules\finance\domain;

/** 只判断本地财务事实；本地一致不代表真实商户已收款或已完成账单核对。 */
final class PaymentAcceptanceAudit
{
    /**
     * 核对原充值、单次入账、来源流转与当前账户。
     * @param array<string,mixed> $evidence 仓储白名单证据
     * @return array<string,mixed> 不含商户密钥、会员资料及渠道交易号的报告
     */
    public static function inspect(array $evidence): array
    {
        $payment = $evidence['payment'];
        $recharge = $evidence['recharge'];
        $attempt = $evidence['attempt'];
        $paid = $payment['status'] === 'paid';
        $checks = [];
        $add = static function (string $code, bool $ok, string $message) use (&$checks): void {
            $checks[] = ['code' => $code, 'status' => $ok ? 'pass' : 'fail', 'message' => $message];
        };
        $add('payment_source', $recharge !== null && $evidence['payments_for_recharge'] === 1
            && $payment['member_id'] === $recharge['member_id'] && $payment['member_id'] === $attempt['member_id']
            && $payment['purpose_id'] === $recharge['id'] && $payment['amount_minor'] === $recharge['amount_minor']
            && $payment['currency'] === 'CNY' && $recharge['currency'] === 'CNY'
            && $payment['channel'] === $recharge['channel'] && $payment['channel'] === 'connection-' . $attempt['channel_id'],
            '支付记录、充值、会员归属、金额、币种与原通道一致');
        if ($paid) {
            $account = $evidence['account'];
            $lot = count($evidence['lots']) === 1 ? $evidence['lots'][0] : null;
            $credit = count($evidence['credits']) === 1 ? $evidence['credits'][0] : null;
            $add('paid_confirmation', ($recharge['status'] ?? null) === 'credited' && $evidence['trade_recorded'] && $evidence['paid_at_recorded'], '付款状态、充值到账、渠道交易号与到账时间完整');
            $add('single_credit', $account !== null && $credit !== null && $credit['entry_type'] === 'credit'
                && $credit['member_id'] === $payment['member_id'] && $credit['account_id'] === $account['id']
                && $credit['amount_minor'] === $payment['amount_minor'], '原充值恰好存在一条正确的入账流水');
            $add('funding_source', $lot !== null && $account !== null && $lot['account_id'] === $account['id']
                && $lot['credited_minor'] === $payment['amount_minor'] && $lot['remaining_minor'] >= 0
                && $evidence['net_consumed_minor'] >= 0 && $evidence['frozen_or_returned_minor'] >= 0
                && $lot['remaining_minor'] + $evidence['net_consumed_minor'] + $evidence['frozen_or_returned_minor'] === $payment['amount_minor'],
                '原充值剩余、净消费与冻结/已退还金额守恒');
            $add('account_balance', $account !== null && $account['available_minor'] >= 0 && $account['frozen_minor'] >= 0
                && $account['available_minor'] === $evidence['account_entry_total_minor']
                && $account['available_minor'] === $evidence['account_lot_total_minor']
                && $account['frozen_minor'] === $evidence['account_frozen_requests_minor'], '当前可用、来源批次、全部流水及冻结申请一致');
        } else {
            $add('no_unconfirmed_credit', $evidence['lots'] === [] && $evidence['credits'] === [] && ($recharge['status'] ?? null) !== 'credited', '未确认付款不产生资金批次或入账流水');
        }
        $inconsistent = in_array('fail', array_column($checks, 'status'), true);
        return [
            'payment_id' => $payment['id'], 'payment_no' => $payment['payment_no'], 'recharge_no' => $recharge['recharge_no'] ?? null,
            'amount_minor' => $payment['amount_minor'], 'currency' => $payment['currency'], 'status' => $payment['status'],
            'plugin_id' => $attempt['plugin_id'], 'channel_id' => $attempt['channel_id'],
            'local_consistency' => $inconsistent ? 'fail' : 'pass',
            'collection_evidence' => $paid && !$inconsistent ? 'local_paid' : 'not_confirmed',
            'check_count' => $attempt['check_count'], 'processed_notifications' => $evidence['processed_notifications'],
            'manual_review' => $attempt['last_error_code'] === 'manual_review', 'checks' => $checks,
        ];
    }
}
