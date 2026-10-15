<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\modules\finance\contract\WalletLedgerRepositoryInterface;
use app\modules\finance\domain\WalletOrderPayment;
use app\modules\finance\infrastructure\model\PaymentTransactionModel;
use app\modules\finance\infrastructure\model\RechargeModel;
use app\modules\finance\infrastructure\model\WalletAccountModel;
use app\modules\finance\infrastructure\model\WalletConsumptionModel;
use app\modules\finance\infrastructure\model\WalletEntryModel;
use app\modules\finance\infrastructure\model\WalletFundingLotModel;
use LogicException;

/** 只读写资金表，事务由公开用例持有。 */
final class DatabaseWalletLedgerRepository implements WalletLedgerRepositoryInterface
{
    public function balance(int $memberId): array
    {
        $account = WalletAccountModel::query()->where('member_id', $memberId)
            ->where('currency', 'CNY')->where('fund_type', 'recharge_cash')->first();
        return ['currency' => 'CNY', 'available_minor' => $account?->available_minor ?? 0, 'frozen_minor' => $account?->frozen_minor ?? 0];
    }

    public function lockRecharge(int $rechargeId): ?array
    {
        $this->requireTransaction();
        $row = RechargeModel::query()->whereKey($rechargeId)->lockForUpdate()->first();
        return $row === null ? null : [
            'id' => $row->id, 'member_id' => $row->member_id, 'amount_minor' => $row->amount_minor,
            'currency' => $row->currency, 'channel' => $row->channel, 'status' => $row->status,
        ];
    }

    public function confirmedRechargePayments(int $rechargeId): array
    {
        return PaymentTransactionModel::query()->where('purpose', 'recharge')->where('purpose_id', $rechargeId)
            ->where('status', 'paid')->lockForUpdate()->get()->map(static fn (PaymentTransactionModel $row): array => [
                'member_id' => $row->member_id, 'amount_minor' => $row->amount_minor, 'currency' => $row->currency, 'channel' => $row->channel,
            ])->all();
    }

    public function lockAccount(int $memberId, string $currency): array
    {
        $this->requireTransaction();
        $now = date('Y-m-d H:i:s');
        // 原子建账户，复合唯一索引处理并发首次入账；随后再取行锁。
        WalletAccountModel::query()->insertOrIgnore([
            'member_id' => $memberId, 'currency' => $currency, 'fund_type' => 'recharge_cash',
            'available_minor' => 0, 'frozen_minor' => 0, 'version' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = WalletAccountModel::query()->where('member_id', $memberId)->where('currency', $currency)
            ->where('fund_type', 'recharge_cash')->lockForUpdate()->firstOrFail();
        return ['id' => $row->id, 'member_id' => $row->member_id, 'available_minor' => $row->available_minor, 'frozen_minor' => $row->frozen_minor, 'version' => $row->version];
    }

    public function orderWalletPayment(int $orderId): ?array
    {
        $row = PaymentTransactionModel::query()->where('purpose', 'order')->where('purpose_id', $orderId)
            ->where('channel', 'wallet')->first();
        return $row === null ? null : [
            'member_id' => $row->member_id, 'amount_minor' => $row->amount_minor, 'currency' => $row->currency, 'status' => $row->status,
        ];
    }

    public function lockFundingLots(int $accountId): array
    {
        $this->requireTransaction();
        return WalletFundingLotModel::query()->where('account_id', $accountId)->where('remaining_minor', '>', 0)
            ->orderBy('id')->lockForUpdate()->get()->map(static fn (WalletFundingLotModel $row): array => [
                'id' => $row->id, 'remaining_minor' => $row->remaining_minor,
            ])->all();
    }

    public function recordCredit(array $account, int $rechargeId, int $amountMinor, int $balance): void
    {
        $this->requireTransaction();
        $now = date('Y-m-d H:i:s');
        WalletFundingLotModel::query()->create([
            'account_id' => $account['id'], 'recharge_id' => $rechargeId,
            'credited_minor' => $amountMinor, 'remaining_minor' => $amountMinor, 'created_at' => $now,
        ]);
        $this->appendEntry($account, 'credit', 'recharge', $rechargeId, $amountMinor, $balance, $now);
        $this->updateBalance($account, $balance);
        RechargeModel::query()->whereKey($rechargeId)->update(['status' => 'credited']);
    }

    public function recordDebit(array $account, WalletOrderPayment $payment, array $allocations, int $balance): void
    {
        $this->requireTransaction();
        $now = date('Y-m-d H:i:s');
        foreach ($allocations as $allocation) {
            WalletFundingLotModel::query()->whereKey($allocation['id'])->update(['remaining_minor' => $allocation['remaining_minor']]);
            WalletConsumptionModel::query()->create([
                'order_id' => $payment->orderId, 'funding_lot_id' => $allocation['id'],
                'consumed_minor' => $allocation['consumed_minor'], 'restored_minor' => 0, 'created_at' => $now,
            ]);
        }
        $this->appendEntry($account, 'spend', 'order', $payment->orderId, -$payment->amountMinor, $balance, $now);
        $this->updateBalance($account, $balance);
        PaymentTransactionModel::query()->create([
            'payment_no' => 'WP' . substr(hash('sha256', $payment->orderNo), 0, 32),
            'member_id' => $payment->memberId, 'purpose' => 'order', 'purpose_id' => $payment->orderId,
            'channel' => 'wallet', 'amount_minor' => $payment->amountMinor, 'currency' => $payment->currency,
            'status' => 'paid', 'paid_at' => $now,
        ]);
    }

    /** @param array{id: int, member_id: int, available_minor: int, version: int} $account 锁定账户 */
    private function appendEntry(array $account, string $type, string $source, int $sourceId, int $amount, int $balance, string $now): void
    {
        WalletEntryModel::query()->create([
            'entry_no' => 'WL' . bin2hex(random_bytes(16)), 'account_id' => $account['id'], 'member_id' => $account['member_id'],
            'entry_type' => $type, 'source_type' => $source, 'source_id' => $sourceId, 'amount_minor' => $amount,
            'available_after_minor' => $balance, 'created_at' => $now,
        ]);
    }

    /** @param array{id: int, member_id: int, available_minor: int, version: int} $account 锁定账户 */
    private function updateBalance(array $account, int $balance): void
    {
        WalletAccountModel::query()->whereKey($account['id'])->update(['available_minor' => $balance, 'version' => $account['version'] + 1]);
    }

    private function requireTransaction(): void
    {
        if ((new WalletAccountModel())->getConnection()->transactionLevel() < 1) {
            throw new LogicException('资金写入必须在默认连接事务中执行');
        }
    }
}
