<?php

declare(strict_types=1);

namespace app\modules\finance\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\finance\contract\FinanceWorkflowRepositoryInterface;
use app\modules\finance\contract\WalletLedgerRepositoryInterface;
use app\modules\finance\domain\FinanceRules;
use app\modules\order\contract\OrderFinanceRepositoryInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\contract\OutboxWriterInterface;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 社区版财务操作门面：单据、资金来源、订单实例与审计在同一事务提交。 */
final readonly class ManageFinanceService
{
    public function __construct(
        private AdminAccessControlService $access,
        private FinanceWorkflowRepositoryInterface $records,
        private WalletLedgerRepositoryInterface $ledger,
        private WalletLedgerService $wallet,
        private OrderFinanceRepositoryInterface $orders,
        private TransactionManagerInterface $transactions,
        private OutboxWriterInterface $outbox,
    ) {
    }

    public function members(AdminAccount $actor, string $resource, string $search): array
    {
        $this->authorize($actor, $resource, 'create');
        return $this->records->members($search);
    }

    public function options(AdminAccount $actor, string $resource, int $memberId): array
    {
        $this->authorize($actor, $resource, 'create');
        return $this->records->options($resource, $memberId);
    }

    public function create(AdminAccount $actor, string $resource, array $input): array
    {
        $this->authorize($actor, $resource, 'create');
        return $this->perform($actor, $resource, 'create', 0, $input, function () use ($actor, $resource, $input): int {
            $memberId = (int) $input['member_id'];
            if (!$this->records->memberExists($memberId)) {
                throw new BusinessException('会员不存在', ApiErrorCode::NOT_FOUND, 404);
            }
            $amount = (int) $input['amount_minor'];
            FinanceRules::amount($amount, 100000000);
            $values = [
                'member_id' => $memberId, 'amount_minor' => $amount, 'currency' => 'CNY',
                'status' => 'pending', 'operator_id' => $actor->id, 'remark' => $input['remark'] ?? '',
            ];
            return match ($resource) {
                'recharges' => $this->recharge($values, $input),
                'refunds' => $this->refund($values, $input),
                'invoices' => $this->invoice($values, $input),
                'withdrawals' => $this->withdrawal($values, $input),
            };
        });
    }

    public function act(AdminAccount $actor, string $resource, int $id, string $action, array $input): array
    {
        $permission = in_array($action, ['approve', 'reject'], true) ? 'review' : $action;
        $this->authorize($actor, $resource, $permission);
        return $this->perform($actor, $resource, $action, $id, $input, function () use ($actor, $resource, $id, $action, $input): int {
            $record = $this->records->lock($resource, $id);
            if (!$this->records->managed($resource, $id)) {
                FinanceRules::conflict('历史单据的额度或资金来源尚未核对，暂时只读');
            }
            if (in_array($action, ['approve', 'reject'], true)) {
                FinanceRules::state($record['status'], 'pending');
                if ($resource === 'refunds' && $action === 'reject') {
                    $orderId = $this->orders->orderId((int) $record['source_id']);
                    $this->orders->lock($orderId);
                    $this->orders->release((int) $record['source_id'], (int) $record['amount_minor']);
                }
                if ($resource === 'invoices') {
                    $this->orders->lock((int) $record['order_id']);
                }
                if ($resource === 'withdrawals' && $action === 'reject') {
                    $this->settleWithdrawal($record, false);
                }
                $this->records->update($resource, $id, [
                    'status' => $action === 'approve' ? 'approved' : 'rejected',
                    'reviewer_id' => $actor->id, 'reviewed_at' => date('Y-m-d H:i:s'),
                    'review_reason' => $input['review_reason'] ?? '',
                ]);
                return $id;
            }
            if ($resource === 'refunds' && $action === 'execute') {
                $this->executeRefund($record);
            } elseif ($resource === 'withdrawals' && $action === 'payout') {
                FinanceRules::state($record['status'], 'approved');
                $this->settleWithdrawal($record, true);
                $this->records->update($resource, $id, ['status' => 'paid',
                    'payout_reference' => $input['external_reference'], 'payout_at' => date('Y-m-d H:i:s')]);
            } elseif ($resource === 'invoices' && $action === 'issue') {
                FinanceRules::state($record['status'], 'approved');
                $this->orders->lock((int) $record['order_id']);
                if ($this->records->invoiceAllocated($id) !== (int) $record['amount_minor']) {
                    FinanceRules::conflict('发票金额归属不完整，不能登记开票');
                }
                $this->records->update($resource, $id, ['status' => 'issued',
                    'external_number' => $input['external_reference'], 'issued_at' => date('Y-m-d H:i:s')]);
            } elseif ($resource === 'invoices' && $action === 'red') {
                FinanceRules::state($record['status'], 'issued');
                $this->orders->lock((int) $record['order_id']);
                $this->records->update($resource, $id, ['status' => 'red',
                    'red_number' => $input['external_reference'], 'red_at' => date('Y-m-d H:i:s')]);
            } else {
                FinanceRules::conflict('该单据不支持此操作');
            }
            return $id;
        });
    }

    private function recharge(array $values, array $input): int
    {
        $values += ['recharge_no' => 'RC' . bin2hex(random_bytes(16)), 'channel' => 'offline',
            'external_reference' => $input['external_reference'], 'paid_at' => date('Y-m-d H:i:s')];
        $values['status'] = 'paid';
        $id = $this->records->create('recharges', $values);
        $this->records->create('payments', [
            'payment_no' => 'OP' . bin2hex(random_bytes(16)), 'member_id' => $values['member_id'],
            'purpose' => 'recharge', 'purpose_id' => $id, 'channel' => 'offline',
            'channel_trade_no' => $input['external_reference'], 'amount_minor' => $values['amount_minor'],
            'currency' => 'CNY', 'status' => 'paid', 'paid_at' => $values['paid_at'],
        ]);
        // 入账门面会再次核对会员、金额、币种、渠道并写入来源与流水。
        $this->wallet->creditConfirmedRecharge($values['member_id'], $id);
        return $id;
    }

    private function refund(array $values, array $input): int
    {
        $unitId = (int) $input['source_id'];
        $order = $this->orders->lock($this->orders->orderId($unitId));
        $this->paidOrder($order, $values['member_id']);
        $unit = $this->unit($order, $unitId);
        $payment = $this->records->paymentForOrder($order['id']);
        if ($payment['channel'] !== 'wallet' || (int) $payment['amount_minor'] !== $order['paid_minor']
            || (int) $payment['member_id'] !== $values['member_id'] || $payment['currency'] !== 'CNY') {
            FinanceRules::conflict('当前仅支持已核实的余额支付订单退款；原渠道退款需接入渠道能力');
        }
        if (!$this->records->serviceReleased($unit['service_id'])) {
            FinanceRules::conflict('实例尚未终止，请先在业务管理完成资源终止');
        }
        $available = $unit['allocated_minor'] - $unit['refunded_minor'] - $unit['reserved_refund_minor']
            - $this->records->invoiceOccupied($unitId);
        FinanceRules::amount($values['amount_minor'], $available);
        $id = $this->records->create('refunds', $values + [
            'refund_no' => 'RF' . bin2hex(random_bytes(16)), 'source_type' => 'order_unit', 'source_id' => $unitId,
            'payment_id' => (int) $payment['id'], 'destination' => 'wallet', 'reason' => $input['reason'],
        ]);
        $this->orders->reserve($unitId, $values['amount_minor']);
        return $id;
    }

    private function invoice(array $values, array $input): int
    {
        $order = $this->orders->lock((int) $input['source_id']);
        $this->paidOrder($order, $values['member_id']);
        $allocations = [];
        $remaining = $values['amount_minor'];
        $occupied = $this->records->invoiceOccupations(array_column($order['units'], 'id'));
        foreach ($order['units'] as $unit) {
            $available = max(0, $unit['allocated_minor'] - $unit['refunded_minor'] - $unit['reserved_refund_minor']
                - ($occupied[$unit['id']] ?? 0));
            $allocated = min($remaining, $available);
            if ($allocated > 0) {
                $allocations[] = ['id' => $unit['id'], 'amount' => $allocated];
                $remaining -= $allocated;
            }
        }
        if ($remaining !== 0) {
            FinanceRules::conflict('开票金额超过订单尚未开票的净实付金额');
        }
        $id = $this->records->create('invoices', $values + [
            'invoice_no' => 'IV' . bin2hex(random_bytes(16)), 'order_id' => $order['id'],
            'invoice_type' => $input['invoice_type'], 'title' => $input['title'],
        ]);
        foreach ($allocations as $allocation) {
            $this->records->allocateInvoice($id, $allocation['id'], $allocation['amount']);
        }
        return $id;
    }

    private function withdrawal(array $values, array $input): int
    {
        $rechargeId = (int) $input['source_id'];
        $recharge = $this->ledger->lockRecharge($rechargeId);
        if ($recharge === null || $recharge['member_id'] !== $values['member_id']
            || $recharge['status'] !== 'credited' || $recharge['currency'] !== 'CNY') {
            FinanceRules::conflict('只允许处理本人已入账的充值剩余资金');
        }
        $account = $this->ledger->lockAccount($values['member_id'], 'CNY');
        $lot = $this->records->lockLot($rechargeId, $account['id']);
        FinanceRules::amount($values['amount_minor'], min($lot['remaining_minor'], $account['available_minor']));
        if ($values['amount_minor'] > PHP_INT_MAX - $account['frozen_minor']) {
            FinanceRules::conflict('冻结余额超出范围');
        }
        $id = $this->records->create('withdrawals', $values + [
            'withdrawal_no' => 'WD' . bin2hex(random_bytes(16)), 'source_type' => 'recharge',
            'source_id' => $rechargeId, 'reason' => $input['reason'],
        ]);
        $available = $account['available_minor'] - $values['amount_minor'];
        $this->records->changeLot($lot['id'], $lot['remaining_minor'] - $values['amount_minor']);
        $this->records->changeAccount($account, $available, $account['frozen_minor'] + $values['amount_minor']);
        $this->records->entry($account, 'withdrawal_freeze', 'withdrawal', $id, -$values['amount_minor'], $available);
        return $id;
    }

    private function settleWithdrawal(array $record, bool $paid): void
    {
        if ($record['source_type'] !== 'recharge') {
            FinanceRules::conflict('此历史提现来源暂不支持处置');
        }
        $account = $this->ledger->lockAccount((int) $record['member_id'], $record['currency']);
        $amount = (int) $record['amount_minor'];
        if ($amount < 1 || $account['frozen_minor'] < $amount
            || !$this->records->withdrawalFrozen((int) $record['id'], $account['id'], $amount)) {
            FinanceRules::conflict('冻结金额不足，需先核对历史提现记录');
        }
        // 必须有创建时的冻结流水，不允许将无来源的历史申请视作已冻结。
        if (!$paid && $amount > PHP_INT_MAX - $account['available_minor']) {
            FinanceRules::conflict('余额超出范围');
        }
        $available = $account['available_minor'] + ($paid ? 0 : $amount);
        if (!$paid) {
            $lot = $this->records->lockLot((int) $record['source_id'], $account['id']);
            if ($lot['remaining_minor'] + $amount > $lot['credited_minor']) {
                FinanceRules::conflict('解除冻结金额超出来源');
            }
            $this->records->changeLot($lot['id'], $lot['remaining_minor'] + $amount);
        }
        $this->records->changeAccount($account, $available, $account['frozen_minor'] - $amount);
        $this->records->entry($account, $paid ? 'withdrawal_payout' : 'withdrawal_unfreeze', 'withdrawal',
            (int) $record['id'], $paid ? 0 : $amount, $available);
    }

    private function executeRefund(array $record): void
    {
        FinanceRules::state($record['status'], 'approved');
        if ($record['source_type'] !== 'order_unit' || $record['destination'] !== 'wallet') {
            FinanceRules::conflict('当前只支持按订单实例退回原余额资金来源');
        }
        $unitId = (int) $record['source_id'];
        $order = $this->orders->lock($this->orders->orderId($unitId));
        $this->paidOrder($order, (int) $record['member_id']);
        $unit = $this->unit($order, $unitId);
        $amount = (int) $record['amount_minor'];
        FinanceRules::amount($amount, min($unit['reserved_refund_minor'], $unit['allocated_minor'] - $unit['refunded_minor']));
        $payment = $this->records->paymentForOrder($order['id']);
        if ($payment['channel'] !== 'wallet' || (int) $payment['id'] !== (int) $record['payment_id']
            || (int) $payment['member_id'] !== (int) $record['member_id'] || $payment['currency'] !== $order['currency']
            || (int) $payment['amount_minor'] !== $order['paid_minor'] || !$this->records->serviceReleased($unit['service_id'])) {
            FinanceRules::conflict('付款来源或实例状态已变化，不能执行退款');
        }
        if ($this->records->invoiceOccupied($unitId) > $unit['allocated_minor'] - $unit['refunded_minor'] - $amount) {
            FinanceRules::conflict('该实例已开票，请先完成红冲');
        }
        $account = $this->ledger->lockAccount((int) $record['member_id'], $record['currency']);
        $this->records->restoreConsumption($order['id'], $account['id'], $amount);
        if ($amount > PHP_INT_MAX - $account['available_minor']) {
            FinanceRules::conflict('余额超出范围');
        }
        $balance = $account['available_minor'] + $amount;
        $this->records->changeAccount($account, $balance, $account['frozen_minor']);
        $this->records->entry($account, 'refund_credit', 'refund', (int) $record['id'], $amount, $balance);
        $this->orders->complete($order['id'], $unitId, $amount);
        $this->records->update('refunds', (int) $record['id'], ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);
        $this->outbox->append('refund.completed.' . $record['id'], 'refund.completed', (int) $record['id'],
            ['order_id' => $order['id'], 'order_unit_id' => $unitId, 'member_id' => (int) $record['member_id'], 'amount_minor' => $amount]);
    }

    private function paidOrder(array $order, int $memberId): void
    {
        if (!$this->records->invoiceIntegrity($order['id'])) {
            FinanceRules::conflict('订单存在未分配金额的历史发票，请先核对');
        }
        $allocated = array_sum(array_column($order['units'], 'allocated_minor'));
        if ($order['member_id'] !== $memberId || $order['currency'] !== 'CNY'
            || !in_array($order['payment_status'], ['paid', 'partial_refund'], true)
            || $order['paid_minor'] < 1 || $allocated !== $order['paid_minor']) {
            FinanceRules::conflict('订单未付清、金额分摊不完整或不属于此会员');
        }
    }

    private function unit(array $order, int $unitId): array
    {
        foreach ($order['units'] as $unit) {
            if ($unit['id'] === $unitId) {
                return $unit;
            }
        }
        FinanceRules::conflict('订单实例不存在');
    }

    private function authorize(AdminAccount $actor, string $resource, string $action): void
    {
        $allowed = [
            'recharges' => ['create'], 'refunds' => ['create', 'review', 'execute'],
            'invoices' => ['create', 'review', 'issue', 'red'], 'withdrawals' => ['create', 'review', 'payout'],
        ];
        if (!in_array($action, $allowed[$resource] ?? [], true)) {
            FinanceRules::conflict('不支持的财务操作');
        }
        $this->access->requirePermission($actor, 'admin.finance.' . $resource . '.view');
        $this->access->requirePermission($actor, 'admin.finance.' . $resource . '.' . $action);
    }

    private function perform(AdminAccount $actor, string $resource, string $action, int $id, array $input, callable $operation): array
    {
        $key = $resource . '.' . $actor->id . '.' . $input['request_key'];
        $body = $input;
        unset($body['request_key']);
        ksort($body);
        $hash = hash('sha256', json_encode([$resource, $action, $id, $body], JSON_THROW_ON_ERROR));
        return $this->transactions->run(function () use ($actor, $resource, $action, $key, $hash, $operation): array {
            $previous = $this->records->begin($key, $hash, $actor->id, $resource, $action);
            if ($previous !== null) {
                return $previous;
            }
            $result = ['id' => $operation()];
            $this->records->finish($key, $result);
            return $result;
        });
    }
}
