<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\exception\BusinessException;
use app\modules\finance\contract\OnlinePaymentRepositoryInterface;
use app\modules\finance\infrastructure\model\{RechargeModel, PaymentTransactionModel, OnlinePaymentAttemptModel, PaymentNotificationModel};
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\http\ApiErrorCode;
use Illuminate\Database\QueryException;
use support\Db;

final readonly class DatabaseOnlinePaymentRepository implements OnlinePaymentRepositoryInterface
{
    public function __construct(private SensitiveDataCipherInterface $cipher, private PluginInstallationRepositoryInterface $installations) {}

    public function request(int $memberId, string $key): ?array
    {
        $id = OnlinePaymentAttemptModel::query()->where('member_id', $memberId)->where('request_key', $key)->value('payment_id');
        return $id === null ? null : $this->find((int) $id);
    }
    public function find(int $paymentId): ?array
    {
        $attempt = OnlinePaymentAttemptModel::query()->whereKey($paymentId)->first();
        $payment = $attempt === null ? null : PaymentTransactionModel::query()->whereKey($paymentId)->first();
        $recharge = $payment === null ? null : RechargeModel::query()->whereKey($payment->purpose_id)->first();
        return $attempt === null || $payment === null || $recharge === null ? null : $this->map($attempt, $payment, $recharge);
    }
    public function recharge(int $memberId, int $rechargeId): ?array
    {
        $payment = PaymentTransactionModel::query()->where('member_id', $memberId)->where('purpose', 'recharge')->where('purpose_id', $rechargeId)->first();
        return $payment === null ? null : $this->find($payment->id);
    }
    public function notification(string $token): ?array
    {
        $id = OnlinePaymentAttemptModel::query()->where('notify_token', $token)->value('payment_id');
        return $id === null ? null : $this->find((int) $id);
    }
    public function listing(int $memberId, int $page, int $size): array
    {
        $q = OnlinePaymentAttemptModel::query()->where('member_id', $memberId);
        $total = (clone $q)->count();
        $ids = $q->orderByDesc('payment_id')->forPage($page, $size)->pluck('payment_id');
        return ['data' => array_values(array_filter(array_map(fn ($id) => $this->find((int) $id), $ids->all()))), 'total' => $total];
    }
    public function reserve(array $values, array $snapshot): array
    {
        try {
            return Db::transaction(function () use ($values, $snapshot): array {
                // 与插件生命周期共享锁，防止停用/升级和建立新交易互相越过。
                $this->installations->lockAccepting($snapshot['plugin_id']);
                $installed = $this->installations->all()[$snapshot['plugin_id']] ?? null;
                if ($installed === null || $installed['package_hash'] !== $snapshot['package_hash']) throw new BusinessException('支付插件已变化，请刷新后重试', ApiErrorCode::CONFLICT, 409);
                $previous = $this->request($values['member_id'], $values['request_key']);
                if ($previous !== null) return $previous;
                $active = PaymentTransactionModel::query()->where('member_id', $values['member_id'])->where('purpose', 'recharge')->whereIn('status', ['creating', 'pending', 'unknown'])->count();
                if ($active >= 10) throw new BusinessException('待处理充值过多，请先核查原充值', ApiErrorCode::TOO_MANY_REQUESTS, 429);
                $recharge = RechargeModel::query()->create(['recharge_no' => 'R' . date('YmdHis') . bin2hex(random_bytes(8)),
                    'member_id' => $values['member_id'], 'amount_minor' => $values['amount_minor'], 'currency' => 'CNY',
                    'channel' => 'connection-' . $values['channel_id'], 'status' => 'pending_payment']);
                $payment = PaymentTransactionModel::query()->create(['payment_no' => 'P' . date('YmdHis') . bin2hex(random_bytes(8)),
                    'member_id' => $values['member_id'], 'purpose' => 'recharge', 'purpose_id' => $recharge->id,
                    'channel' => 'connection-' . $values['channel_id'], 'amount_minor' => $values['amount_minor'], 'currency' => 'CNY', 'status' => 'creating']);
                OnlinePaymentAttemptModel::query()->create(['payment_id' => $payment->id, 'member_id' => $values['member_id'],
                    'channel_id' => $values['channel_id'], 'channel_revision' => $snapshot['channel_revision'], 'plugin_id' => $snapshot['plugin_id'],
                    'package_hash' => $snapshot['package_hash'], 'configuration_encrypted' => $this->cipher->encrypt(json_encode($snapshot['configuration']->values, JSON_THROW_ON_ERROR)),
                    'request_key' => $values['request_key'], 'request_hash' => $values['request_hash'], 'notify_token' => $values['notify_token'], 'notify_url' => $values['notify_url'],
                    'expires_at' => date('Y-m-d H:i:s', time() + 900), 'reconcile_until' => date('Y-m-d H:i:s', time() + 604800),
                    'next_check_at' => date('Y-m-d H:i:s', time() + 60), 'check_count' => 0,
                    'client_ip' => $values['origin']['client_ip'], 'client_ip_location' => $values['origin']['client_ip_location']]);
                return $this->find($payment->id);
            });
        } catch (QueryException $error) {
            if ((string) $error->getCode() === '23000' && ($existing = $this->request($values['member_id'], $values['request_key'])) !== null) return $existing;
            throw $error;
        }
    }
    public function claim(int $paymentId, bool $create, bool $automatic = false): ?array
    {
        return Db::transaction(function () use ($paymentId, $create, $automatic): ?array {
            $row = $this->lock($paymentId);
            $now = date('Y-m-d H:i:s');
            if (in_array($row['status'], ['paid', 'closed', 'failed'], true) || ($row['lease_until'] !== null && $row['lease_until'] > $now)) return null;
            if ($create && ($row['status'] !== 'creating' || $row['lease_token'] !== null)) return null;
            if (!$create && $row['checked_at'] !== null && strtotime($row['checked_at']) > time() - 5) return null;
            if ($automatic && $row['reconcile_until'] <= $now) {
                OnlinePaymentAttemptModel::query()->whereKey($paymentId)->update(['next_check_at' => null, 'last_error_code' => 'manual_review']);
                return null;
            }
            $lease = bin2hex(random_bytes(32));
            $values = ['lease_token' => $lease, 'lease_until' => date('Y-m-d H:i:s', time() + 120)];
            if (!$create) $values += ['checked_at' => $now, 'check_count' => $row['check_count'] + 1];
            OnlinePaymentAttemptModel::query()->whereKey($paymentId)->update($values);
            return array_replace($row, $values);
        });
    }
    public function lock(int $paymentId): array
    {
        if (Db::connection()->transactionLevel() < 1) throw new \LogicException('付款锁必须在业务事务内');
        $lookup = PaymentTransactionModel::query()->whereKey($paymentId)->first();
        if ($lookup === null) throw new BusinessException('充值不存在', ApiErrorCode::NOT_FOUND, 404);
        $recharge = RechargeModel::query()->whereKey($lookup->purpose_id)->lockForUpdate()->firstOrFail();
        $payment = PaymentTransactionModel::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
        $attempt = OnlinePaymentAttemptModel::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
        return $this->map($attempt, $payment, $recharge);
    }
    public function update(array $attempt, string $status, ?string $tradeNo, ?string $qrCode, ?string $error): void
    {
        $paid = $status === 'paid';
        PaymentTransactionModel::query()->whereKey($attempt['payment_id'])->update(['status' => $status,
            'channel_trade_no' => $tradeNo ?? $attempt['channel_trade_no'], 'paid_at' => $paid ? ($attempt['paid_at'] ?? date('Y-m-d H:i:s')) : null]);
        $rechargeState = $paid ? ($attempt['recharge_status'] === 'credited' ? 'credited' : 'paid') : (in_array($status, ['closed', 'failed'], true) ? $status : 'pending_payment');
        RechargeModel::query()->whereKey($attempt['recharge_id'])->update(['status' => $rechargeState, 'paid_at' => $paid ? ($attempt['paid_at'] ?? date('Y-m-d H:i:s')) : null]);
        $terminal = in_array($status, ['paid', 'closed', 'failed'], true);
        OnlinePaymentAttemptModel::query()->whereKey($attempt['payment_id'])->update(['qr_code' => $terminal ? null : ($qrCode ?? $attempt['qr_code']),
            'lease_token' => null, 'lease_until' => null, 'next_check_at' => $terminal ? null : date('Y-m-d H:i:s', time() + min(1800, 60 * (1 + $attempt['check_count']))), 'last_error_code' => $error]);
    }
    public function recordNotification(array $attempt, array $notification): void
    {
        $scope = 'connection-' . $attempt['channel_id'];
        $existing = PaymentNotificationModel::query()->where('channel', $scope)->where('notification_id', $notification['id'])->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->payment_id !== $attempt['payment_id']) throw new BusinessException('通知标识冲突', ApiErrorCode::CONFLICT, 409);
            return;
        }
        PaymentNotificationModel::query()->create(['channel' => $scope, 'notification_id' => $notification['id'], 'payment_id' => $attempt['payment_id'],
            'status' => 'processed', 'received_at' => date('Y-m-d H:i:s'), 'processed_at' => date('Y-m-d H:i:s'),
            'client_ip' => $notification['client_ip'], 'client_ip_location' => $notification['client_ip_location'], 'content_hash' => $notification['content_hash']]);
    }
    public function due(int $limit): array
    {
        return OnlinePaymentAttemptModel::query()->whereNotNull('next_check_at')->where('next_check_at', '<=', date('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', date('Y-m-d H:i:s')))
            ->orderBy('next_check_at')->limit($limit)->pluck('payment_id')->all();
    }
    private function map(OnlinePaymentAttemptModel $attempt, PaymentTransactionModel $payment, RechargeModel $recharge): array
    {
        $attributes = $attempt->getAttributes();
        foreach (['payment_id', 'member_id', 'channel_id', 'channel_revision', 'check_count'] as $key) $attributes[$key] = (int) $attributes[$key];
        return $attributes + ['recharge_id' => (int) $recharge->id, 'payment_no' => $payment->payment_no, 'recharge_no' => $recharge->recharge_no,
            'status' => $payment->status, 'amount_minor' => (int) $payment->amount_minor, 'currency' => $payment->currency,
            'channel_trade_no' => $payment->channel_trade_no, 'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'), 'recharge_status' => $recharge->status];
    }
}
