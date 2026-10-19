<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\modules\finance\contract\PaymentAcceptanceReadRepositoryInterface;
use app\modules\finance\infrastructure\model\{OnlinePaymentAttemptModel, PaymentTransactionModel, RechargeModel, WalletAccountModel, WalletConsumptionModel, WalletEntryModel, WalletFundingLotModel, WithdrawalRequestModel, PaymentNotificationModel};
use support\Db;

/** 所有查询只读；不加载商户配置、通知路由、签名正文或会员联系方式。 */
final class DatabasePaymentAcceptanceReadRepository implements PaymentAcceptanceReadRepositoryInterface
{
    /** @inheritDoc */
    public function evidence(int $paymentId): ?array
    {
        return Db::transaction(function () use ($paymentId): ?array {
            $attempt = OnlinePaymentAttemptModel::query()->whereKey($paymentId)->first(['payment_id', 'member_id', 'channel_id', 'plugin_id', 'check_count', 'checked_at', 'next_check_at', 'last_error_code', 'lease_until']);
            $payment = PaymentTransactionModel::query()->whereKey($paymentId)->first(['id', 'payment_no', 'member_id', 'purpose', 'purpose_id', 'amount_minor', 'currency', 'channel', 'status', 'channel_trade_no', 'paid_at']);
            if ($attempt === null || $payment === null || $payment->purpose !== 'recharge') {
                return null;
            }
            $recharge = RechargeModel::query()->whereKey($payment->purpose_id)->first(['id', 'recharge_no', 'member_id', 'amount_minor', 'currency', 'channel', 'status']);
            $lots = WalletFundingLotModel::query()->where('recharge_id', $payment->purpose_id)->get(['id', 'account_id', 'credited_minor', 'remaining_minor']);
            $account = WalletAccountModel::query()->where('member_id', $payment->member_id)->where('currency', 'CNY')->where('fund_type', 'recharge_cash')
                ->first(['id', 'available_minor', 'frozen_minor']);
            $credits = WalletEntryModel::query()->where('source_type', 'recharge')->where('source_id', $payment->purpose_id)
                ->get(['member_id', 'account_id', 'entry_type', 'amount_minor']);
            $consumed = WalletConsumptionModel::query()->whereIn('funding_lot_id', $lots->pluck('id'))
                ->selectRaw('COALESCE(SUM(consumed_minor - restored_minor), 0) AS total')->first();
            $returned = (int) WithdrawalRequestModel::query()->where('source_type', 'recharge')->where('source_id', $payment->purpose_id)
                ->whereIn('status', ['pending', 'approved', 'paid'])->sum('amount_minor');
            $paymentsForRecharge = PaymentTransactionModel::query()->where('purpose', 'recharge')->where('purpose_id', $payment->purpose_id)->count();
            return [
                'payment' => $payment->only(['id', 'payment_no', 'member_id', 'purpose_id', 'amount_minor', 'currency', 'channel', 'status']),
                'trade_recorded' => is_string($payment->channel_trade_no) && $payment->channel_trade_no !== '',
                'paid_at_recorded' => $payment->paid_at !== null,
                'attempt' => $attempt->only(['payment_id', 'member_id', 'channel_id', 'plugin_id', 'check_count', 'checked_at', 'next_check_at', 'last_error_code', 'lease_until']),
                'recharge' => $recharge?->only(['id', 'recharge_no', 'member_id', 'amount_minor', 'currency', 'channel', 'status']),
                'payments_for_recharge' => $paymentsForRecharge,
                'lots' => $lots->map(static fn (WalletFundingLotModel $lot): array => $lot->only(['id', 'account_id', 'credited_minor', 'remaining_minor']))->all(),
                'credits' => $credits->map(static fn (WalletEntryModel $entry): array => $entry->only(['member_id', 'account_id', 'entry_type', 'amount_minor']))->all(),
                'net_consumed_minor' => (int) $consumed->total,
                'frozen_or_returned_minor' => $returned,
                'account' => $account?->only(['id', 'available_minor', 'frozen_minor']),
                'account_entry_total_minor' => $account === null ? 0 : (int) WalletEntryModel::query()->where('account_id', $account->id)->sum('amount_minor'),
                'account_lot_total_minor' => $account === null ? 0 : (int) WalletFundingLotModel::query()->where('account_id', $account->id)->sum('remaining_minor'),
                'account_frozen_requests_minor' => (int) WithdrawalRequestModel::query()->where('member_id', $payment->member_id)->where('currency', 'CNY')->whereIn('status', ['pending', 'approved'])->sum('amount_minor'),
                'processed_notifications' => PaymentNotificationModel::query()->where('payment_id', $paymentId)->where('status', 'processed')->count(),
            ];
        });
    }
}
