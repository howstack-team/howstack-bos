<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\modules\finance\contract\FinanceWorkflowRepositoryInterface;
use app\modules\finance\domain\FinanceRules;
use app\modules\finance\infrastructure\model\FinanceOperationModel;
use app\modules\finance\infrastructure\model\RechargeModel;
use app\modules\finance\infrastructure\model\PaymentTransactionModel;
use app\modules\finance\infrastructure\model\RefundRequestModel;
use app\modules\finance\infrastructure\model\InvoiceRecordModel;
use app\modules\finance\infrastructure\model\InvoiceAllocationModel;
use app\modules\finance\infrastructure\model\WithdrawalRequestModel;
use app\modules\finance\infrastructure\model\WalletAccountModel;
use app\modules\finance\infrastructure\model\WalletFundingLotModel;
use app\modules\finance\infrastructure\model\WalletConsumptionModel;
use app\modules\finance\infrastructure\model\WalletEntryModel;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\Db;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class DatabaseFinanceWorkflowRepository implements FinanceWorkflowRepositoryInterface
{
    /** @param \app\modules\service_instance\contract\UndeliveredRefundGuardInterface $delivery 首次交付证据查询 */
    public function __construct(private readonly \app\modules\service_instance\contract\UndeliveredRefundGuardInterface $delivery) {}

    public function begin(string $key, string $hash, int $actorId, string $resource, string $action, array $audit = []): ?array
    {
        $this->requireTransaction();
        $inserted = FinanceOperationModel::query()->insertOrIgnore([
            'request_key' => $key, 'request_hash' => $hash, 'actor_id' => $actorId,
            'client_ip' => $audit['client_ip'] ?? null, 'client_ip_location' => $audit['client_ip_location'] ?? null,
            'resource' => $resource, 'action' => $action, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $operation = FinanceOperationModel::query()->where('request_key', $key)->lockForUpdate()->firstOrFail();
        if (!hash_equals($operation->request_hash, $hash)) {
            FinanceRules::conflict('同一请求标识不能用于不同的财务操作');
        }
        if ($inserted === 0) {
            if ($operation->result_json === null) {
                FinanceRules::conflict('操作尚未完成，请稍后重试');
            }
            return json_decode($operation->result_json, true, 512, JSON_THROW_ON_ERROR);
        }
        return null;
    }

    public function finish(string $key, array $result): void
    {
        FinanceOperationModel::query()->where('request_key', $key)
            ->update(['result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
    }

    public function members(string $search): array
    {
        $query = Db::table('members');
        if ($search !== '') {
            $query->where(static function ($query) use ($search): void {
                $query->where('nickname', 'like', '%' . $search . '%')->orWhere('mobile', 'like', '%' . $search . '%');
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }
        return $query->orderByDesc('id')->limit(20)->get(['id', 'nickname'])
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'label' => $row->nickname . '（ID：' . $row->id . '）'])->all();
    }

    public function managed(string $resource, int $id): bool
    {
        return FinanceOperationModel::query()->where('resource', $resource)->where('action', 'create')
            ->where('result_json', json_encode(['id' => $id], JSON_THROW_ON_ERROR))->exists();
    }

    public function invoiceIntegrity(int $orderId): bool
    {
        $invoices = InvoiceRecordModel::query()->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'approved', 'issued'])->get(['id', 'amount_minor']);
        $totals = InvoiceAllocationModel::query()->whereIn('invoice_id', $invoices->pluck('id'))
            ->selectRaw('invoice_id, SUM(amount_minor) AS total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
        foreach ($invoices as $invoice) {
            if ((int) ($totals[$invoice->id] ?? 0) !== $invoice->amount_minor) {
                return false;
            }
        }
        return true;
    }

    public function memberExists(int $memberId): bool
    {
        return Db::table('members')->where('id', $memberId)->exists();
    }

    public function create(string $resource, array $values): int
    {
        $this->requireTransaction();
        try {
            return (int) $this->query($resource)->create($values)->id;
        } catch (\Illuminate\Database\QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062 || str_contains($error->getMessage(), 'UNIQUE constraint')) {
                FinanceRules::conflict('收款凭证或单据已存在，请刷新核对');
            }
            throw $error;
        }
    }

    public function lock(string $resource, int $id): array
    {
        $this->requireTransaction();
        $record = $this->query($resource)->whereKey($id)->lockForUpdate()->first();
        if ($record === null) {
            throw new BusinessException('财务单据不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        return $record->getAttributes();
    }

    public function update(string $resource, int $id, array $values): void
    {
        $this->requireTransaction();
        if ($resource === 'withdrawals' && isset($values['receipt_key'], $values['payout_reference'])
            && WithdrawalRequestModel::query()->where('id', '<>', $id)
                ->whereRaw('LOWER(TRIM(payout_reference)) = ?', [mb_strtolower(trim($values['payout_reference']))])->exists()) {
            FinanceRules::conflict('此打款凭证已在历史单据登记，请核对实际资金退还结果');
        }
        try {
            $this->query($resource)->whereKey($id)->firstOrFail()->fill($values)->save();
        } catch (\Illuminate\Database\QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062 || str_contains($error->getMessage(), 'UNIQUE constraint')) {
                FinanceRules::conflict('此打款凭证已经登记，请核对实际资金退还结果');
            }
            throw $error;
        }
    }

    public function paymentForOrder(int $orderId): array
    {
        $payments = PaymentTransactionModel::query()->where('purpose', 'order')->where('purpose_id', $orderId)
            ->where('status', 'paid')->get(['id', 'member_id', 'amount_minor', 'currency', 'channel']);
        if ($payments->count() !== 1) {
            FinanceRules::conflict('需要唯一且已核实的订单支付记录');
        }
        return $payments->first()->getAttributes();
    }

    public function invoiceOccupations(array $unitIds): array
    {
        return InvoiceAllocationModel::query()->whereIn('order_unit_id', $unitIds)
            ->whereIn('invoice_id', InvoiceRecordModel::query()->whereIn('status', ['pending', 'approved', 'issued'])->select('id'))
            ->selectRaw('order_unit_id, SUM(amount_minor) AS total')->groupBy('order_unit_id')
            ->pluck('total', 'order_unit_id')->map(static fn ($amount): int => (int) $amount)->all();
    }

    public function invoiceOccupied(int $unitId): int
    {
        return $this->invoiceOccupations([$unitId])[$unitId] ?? 0;
    }

    public function allocateInvoice(int $invoiceId, int $unitId, int $amount): void
    {
        InvoiceAllocationModel::query()->create(['invoice_id' => $invoiceId, 'order_unit_id' => $unitId, 'amount_minor' => $amount]);
    }

    public function restoreConsumption(int $orderId, int $accountId, int $amount): void
    {
        $this->requireTransaction();
        $consumptions = WalletConsumptionModel::query()->where('order_id', $orderId)->orderBy('funding_lot_id')
            ->lockForUpdate()->get();
        $remaining = $amount;
        foreach ($consumptions as $consumption) {
            if ($remaining === 0) {
                break;
            }
            $restored = min($remaining, $consumption->consumed_minor - $consumption->restored_minor);
            if ($restored <= 0) {
                continue;
            }
            $lot = WalletFundingLotModel::query()->whereKey($consumption->funding_lot_id)
                ->where('account_id', $accountId)->lockForUpdate()->firstOrFail();
            if ($lot->remaining_minor + $restored > $lot->credited_minor) {
                throw new LogicException('资金来源恢复超过原入账金额');
            }
            $lot->fill(['remaining_minor' => $lot->remaining_minor + $restored])->save();
            $consumption->fill(['restored_minor' => $consumption->restored_minor + $restored])->save();
            $remaining -= $restored;
        }
        if ($remaining !== 0) {
            FinanceRules::conflict('订单余额消费来源不足，不能退款');
        }
    }

    public function lockLot(int $rechargeId, int $accountId): array
    {
        $this->requireTransaction();
        $lot = WalletFundingLotModel::query()->where('recharge_id', $rechargeId)->where('account_id', $accountId)
            ->lockForUpdate()->first();
        if ($lot === null) {
            FinanceRules::conflict('充值来源不存在或不属于此会员');
        }
        return ['id' => $lot->id, 'remaining_minor' => $lot->remaining_minor, 'credited_minor' => $lot->credited_minor];
    }

    public function changeLot(int $lotId, int $remaining): void
    {
        WalletFundingLotModel::query()->whereKey($lotId)->update(['remaining_minor' => $remaining]);
    }

    public function changeAccount(array $account, int $available, int $frozen): void
    {
        if ($available < 0 || $frozen < 0) {
            throw new LogicException('资金余额不能为负数');
        }
        WalletAccountModel::query()->whereKey($account['id'])->update([
            'available_minor' => $available, 'frozen_minor' => $frozen, 'version' => $account['version'] + 1,
        ]);
    }

    public function withdrawalFrozen(int $id, int $accountId, int $amount): bool
    {
        return WalletEntryModel::query()->where('source_type', 'withdrawal')->where('source_id', $id)
            ->where('entry_type', 'withdrawal_freeze')->where('account_id', $accountId)->where('amount_minor', -$amount)->exists();
    }

    public function invoiceAllocated(int $invoiceId): int
    {
        return (int) InvoiceAllocationModel::query()->where('invoice_id', $invoiceId)->sum('amount_minor');
    }

    public function entry(array $account, string $type, string $source, int $sourceId, int $amount, int $balance): void
    {
        WalletEntryModel::query()->create([
            'entry_no' => 'WL' . bin2hex(random_bytes(16)), 'account_id' => $account['id'],
            'member_id' => $account['member_id'], 'entry_type' => $type, 'source_type' => $source,
            'source_id' => $sourceId, 'amount_minor' => $amount, 'available_after_minor' => $balance,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function options(string $resource, int $memberId): array
    {
        if ($resource === 'withdrawals') {
            return RechargeModel::query()->where('member_id', $memberId)->where('status', 'credited')
                ->whereHas('fundingLot', static fn (Builder $query) => $query->where('remaining_minor', '>', 0))
                ->with('fundingLot:id,recharge_id,remaining_minor')->orderByDesc('id')->limit(100)->get()
                ->map(static fn (RechargeModel $row): array => [
                    'id' => $row->id, 'label' => $row->recharge_no, 'available_minor' => $row->fundingLot->remaining_minor,
                ])->all();
        }
        // 跨模块只读选项，显式安全字段；业务写入仍通过订单契约。
        $orders = Db::table('orders')->where('member_id', $memberId)
            ->whereIn('payment_status', ['paid', 'partial_refund'])->orderByDesc('id')->limit(100)
            ->get(['id', 'order_no', 'currency']);
        $items = Db::table('order_items')->whereIn('order_id', $orders->pluck('id'))->get(['id', 'order_id', 'product_name']);
        $units = Db::table('order_item_units')->whereIn('order_item_id', $items->pluck('id'))
            ->orderBy('id')->get(['id', 'order_item_id', 'unit_index', 'allocated_minor', 'refunded_minor', 'reserved_refund_minor', 'service_id']);
        $occupied = InvoiceAllocationModel::query()->whereIn('order_unit_id', $units->pluck('id'))
            ->whereIn('invoice_id', InvoiceRecordModel::query()->whereIn('status', ['pending', 'approved', 'issued'])->select('id'))
            ->selectRaw('order_unit_id, SUM(amount_minor) AS total')->groupBy('order_unit_id')->pluck('total', 'order_unit_id');
        $payments = PaymentTransactionModel::query()->where('purpose', 'order')->where('status', 'paid')
            ->whereIn('purpose_id', $orders->pluck('id'))->get(['purpose_id', 'channel', 'amount_minor', 'currency']);
        $eligible = array_fill_keys($this->delivery->eligibleUnits($units->pluck('id')->map(static fn ($id): int => (int) $id)->all()), true);
        $invoices = InvoiceRecordModel::query()->whereIn('order_id', $orders->pluck('id'))
            ->whereIn('status', ['pending', 'approved', 'issued'])->get(['id', 'order_id', 'amount_minor']);
        $invoiceTotals = InvoiceAllocationModel::query()->whereIn('invoice_id', $invoices->pluck('id'))
            ->selectRaw('invoice_id, SUM(amount_minor) AS total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
        $incompleteOrders = [];
        foreach ($invoices as $invoice) {
            if ((int) ($invoiceTotals[$invoice->id] ?? 0) !== $invoice->amount_minor) {
                $incompleteOrders[$invoice->order_id] = true;
            }
        }
        $result = [];
        foreach ($orders as $order) {
            if (isset($incompleteOrders[$order->id])) {
                continue;
            }
            $orderUnits = $units->filter(static fn ($unit) => $items->contains(
                static fn ($item) => $item->id === $unit->order_item_id && $item->order_id === $order->id));
            $total = 0;
            foreach ($orderUnits as $unit) {
                $available = (int) $unit->allocated_minor - (int) $unit->refunded_minor
                    - (int) $unit->reserved_refund_minor - (int) ($occupied[$unit->id] ?? 0);
                $total += max(0, $available);
                if ($resource === 'refunds' && $available === (int) $unit->allocated_minor && $available > 0 && isset($eligible[$unit->id])
                    && $payments->where('purpose_id', $order->id)->count() === 1
                    && $payments->firstWhere('purpose_id', $order->id)?->channel === 'wallet') {
                    $item = $items->firstWhere('id', $unit->order_item_id);
                    $result[] = ['id' => (int) $unit->id, 'label' => $order->order_no . ' · ' . $item->product_name . ' · 实例 ' . $unit->unit_index,
                        'order_id' => (int) $order->id, 'available_minor' => $available];
                }
            }
            if ($resource === 'invoices' && $total > 0) {
                $result[] = ['id' => (int) $order->id, 'label' => $order->order_no, 'available_minor' => $total];
            }
        }
        return $result;
    }

    private function query(string $resource): Builder
    {
        return match ($resource) {
            'recharges' => RechargeModel::query(), 'payments' => PaymentTransactionModel::query(),
            'refunds' => RefundRequestModel::query(), 'invoices' => InvoiceRecordModel::query(),
            'withdrawals' => WithdrawalRequestModel::query(),
            default => throw new LogicException('不支持的财务单据类型'),
        };
    }

    private function requireTransaction(): void
    {
        if ((new WalletAccountModel())->getConnection()->transactionLevel() < 1) {
            throw new LogicException('财务写入必须在默认连接事务中执行');
        }
    }
}
