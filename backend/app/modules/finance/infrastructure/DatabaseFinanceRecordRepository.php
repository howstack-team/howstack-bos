<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\modules\finance\infrastructure\model\FinanceOutboxModel;
use app\modules\finance\infrastructure\model\InvoiceAllocationModel;
use app\modules\finance\infrastructure\model\InvoiceRecordModel;
use app\modules\finance\infrastructure\model\PaymentNotificationModel;
use app\modules\finance\infrastructure\model\PaymentTransactionModel;
use app\modules\finance\infrastructure\model\RechargeModel;
use app\modules\finance\infrastructure\model\RefundAttemptModel;
use app\modules\finance\infrastructure\model\RefundRequestModel;
use app\modules\finance\infrastructure\model\WalletAccountModel;
use app\modules\finance\infrastructure\model\WalletConsumptionModel;
use app\modules\finance\infrastructure\model\WalletEntryModel;
use app\modules\finance\infrastructure\model\WalletFundingLotModel;
use app\modules\finance\infrastructure\model\WithdrawalRequestModel;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use support\Model;
use app\shared\infrastructure\TableQueryOrder;
use app\modules\finance\contract\FinanceRecordRepositoryInterface;

/** 仅查询明确列出的财务字段，不暴露外部原始报文和收款人资料。 */
final class DatabaseFinanceRecordRepository implements FinanceRecordRepositoryInterface
{
    /** @var array<string, array{table: string, columns: list<string>, number: string}> */
    private const array RESOURCES = [
        'recharges' => [
            'table' => 'recharges', 'number' => 'recharge_no',
            'columns' => ['id', 'recharge_no', 'member_id', 'amount_minor', 'currency', 'channel', 'status', 'paid_at', 'created_at', 'operator_id', 'remark', 'external_reference'],
        ],
        'payments' => [
            'table' => 'payment_transactions', 'number' => 'payment_no',
            'columns' => ['id', 'payment_no', 'member_id', 'purpose', 'purpose_id', 'channel', 'channel_trade_no', 'amount_minor', 'currency', 'status', 'paid_at', 'created_at'],
        ],
        'transactions' => [
            'table' => 'wallet_entries', 'number' => 'entry_no',
            'columns' => ['id', 'entry_no', 'member_id', 'entry_type', 'source_type', 'source_id', 'amount_minor', 'available_after_minor', 'created_at'],
        ],
        'refunds' => [
            'table' => 'refund_requests', 'number' => 'refund_no',
            'columns' => ['id', 'refund_no', 'member_id', 'source_type', 'source_id', 'payment_id', 'amount_minor', 'currency', 'destination', 'status', 'reason', 'reviewer_id', 'reviewed_at', 'completed_at', 'created_at', 'operator_id', 'remark', 'review_reason', 'delivery_task_id', 'delivery_evidence', 'executor_id'],
        ],
        'invoices' => [
            'table' => 'invoice_records', 'number' => 'invoice_no',
            'columns' => ['id', 'invoice_no', 'member_id', 'order_id', 'red_of_id', 'amount_minor', 'currency', 'invoice_type', 'title', 'status', 'external_number', 'reviewer_id', 'reviewed_at', 'issued_at', 'created_at', 'operator_id', 'remark', 'review_reason', 'red_number', 'red_at'],
        ],
        'withdrawals' => [
            'table' => 'withdrawal_requests', 'number' => 'withdrawal_no',
            'columns' => ['id', 'withdrawal_no', 'member_id', 'source_type', 'source_id', 'amount_minor', 'currency', 'status', 'reason', 'reviewer_id', 'reviewed_at', 'payout_reference', 'payout_at', 'created_at', 'operator_id', 'remark', 'review_reason', 'executor_id', 'payout_note'],
        ],
    ];

    public function paginate(
        string $resource,
        int $page,
        int $pageSize,
        ?string $status,
        array $filters = [],
    ): array
    {
        $definition = self::RESOURCES[$resource];
        $query = $this->modelQuery($definition['table']);
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        foreach (['number' => $definition['number'], 'member_id' => 'member_id'] as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                if ($key === 'member_id') {
                    $query->where($column, (int) $filters[$key]);
                } else {
                    $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters[$key]) . '%';
                    $query->whereRaw($query->getQuery()->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
                }
            }
        }
        foreach (['created_from' => '>=', 'created_to' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->where('created_at', $operator, $filters[$key] . ($key === 'created_to' ? ' 23:59:59' : ' 00:00:00'));
            }
        }
        $total = (int) (clone $query)->count();
        $sortColumns = [];
        foreach ($definition['columns'] as $column) {
            if (str_ends_with($column, '_at') || in_array($column, ['amount_minor', 'available_after_minor'], true)) {
                $sortColumns[$column] = $column;
            }
        }
        $rows = TableQueryOrder::apply($query, $filters, $sortColumns)
            ->select($definition['columns'])->forPage($page, $pageSize)->get();

        return ['data' => array_map(self::normalize(...), $rows->all()), 'total' => $total];
    }

    public function find(string $resource, int $id): ?array
    {
        $definition = self::RESOURCES[$resource];
        $row = $this->modelQuery($definition['table'])->select($definition['columns'])->where('id', $id)->first();

        return $row instanceof Model ? self::normalize($row) : null;
    }

    public function hasMemberRecords(int $memberId): bool
    {
        foreach (['wallet_accounts', 'recharges', 'payment_transactions', 'wallet_entries',
            'refund_requests', 'invoice_records', 'withdrawal_requests'] as $table) {
            if ($this->modelQuery($table)->where('member_id', $memberId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function normalize(Model $row): array
    {
        $data = $row->getAttributes();
        foreach (['id', 'member_id', 'purpose_id', 'source_id', 'payment_id', 'order_id', 'red_of_id', 'reviewer_id', 'amount_minor', 'available_after_minor', 'operator_id', 'executor_id', 'delivery_task_id'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = (int) $data[$field];
            }
        }

        return $data;
    }

    /** 固定表名到模型的映射，拒绝动态表名。 */
    private function modelQuery(string $table): EloquentBuilder
    {
        return match ($table) {
            'finance_outbox' => FinanceOutboxModel::query(),
            'invoice_allocations' => InvoiceAllocationModel::query(),
            'invoice_records' => InvoiceRecordModel::query(),
            'payment_notifications' => PaymentNotificationModel::query(),
            'payment_transactions' => PaymentTransactionModel::query(),
            'recharges' => RechargeModel::query(),
            'refund_attempts' => RefundAttemptModel::query(),
            'refund_requests' => RefundRequestModel::query(),
            'wallet_accounts' => WalletAccountModel::query(),
            'wallet_consumptions' => WalletConsumptionModel::query(),
            'wallet_entries' => WalletEntryModel::query(),
            'wallet_funding_lots' => WalletFundingLotModel::query(),
            'withdrawal_requests' => WithdrawalRequestModel::query(),
            default => throw new \InvalidArgumentException('不支持的数据表'),
        };
    }
}
