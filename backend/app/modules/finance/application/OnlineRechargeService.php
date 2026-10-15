<?php

declare(strict_types=1);

namespace app\modules\finance\application;

use app\exception\BusinessException;
use app\modules\finance\contract\{OnlinePaymentRepositoryInterface, OnlinePaymentGatewayInterface};
use app\modules\finance\domain\OnlinePaymentState;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\payment\{PaymentResult, PaymentRequest, PaymentNotification};
use app\shared\plugin\exception\PluginException;

final readonly class OnlineRechargeService
{
    public function __construct(private OnlinePaymentRepositoryInterface $payments, private OnlinePaymentGatewayInterface $gateway,
        private WalletLedgerService $wallet, private TransactionManagerInterface $transactions, private GetSystemSettingsService $settings) {}

    /** @return array{enabled: bool, currency: string, min_minor: int, max_minor: int, channels: list<array{id: int, name: string, plugin_id: string}>} */
    public function options(): array
    {
        $base = $this->callbackBase();
        $channels = PaymentRequest::validUrl($base . '/payment-notify/' . str_repeat('0', 64)) ? $this->gateway->channels() : [];
        return ['enabled' => $channels !== [], 'currency' => 'CNY', 'min_minor' => 1, 'max_minor' => 100000000, 'channels' => $channels];
    }

    /** @param array{client_ip: string, client_ip_location: string} $origin @return array<string, mixed> */
    public function create(int $memberId, int $channelId, int $amountMinor, string $key, array $origin): array
    {
        if ($amountMinor < 1 || $amountMinor > 100000000 || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $key)) throw new BusinessException('充值参数无效', ApiErrorCode::VALIDATION_FAILED, 422);
        $hash = hash('sha256', json_encode([$memberId, $channelId, $amountMinor, 'CNY'], JSON_THROW_ON_ERROR));
        $row = $this->payments->request($memberId, $key);
        if ($row === null) {
            $token = bin2hex(random_bytes(32));
            $notifyUrl = $this->callbackBase() . '/payment-notify/' . $token;
            if (!PaymentRequest::validUrl($notifyUrl)) throw new BusinessException('在线充值暂不可用，请联系平台', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
            $snapshot = $this->gateway->capture($channelId);
            $row = $this->payments->reserve(['member_id' => $memberId, 'channel_id' => $channelId, 'amount_minor' => $amountMinor,
                'request_key' => $key, 'request_hash' => $hash, 'notify_token' => $token, 'notify_url' => $notifyUrl, 'origin' => $origin], $snapshot);
        }
        if (!hash_equals($row['request_hash'], $hash)) throw new BusinessException('同一充值请求不能更改金额或支付方式', ApiErrorCode::CONFLICT, 409);
        $claim = $this->payments->claim((int) $row['payment_id'], true);
        if ($claim !== null) {
            try { $result = $this->gateway->execute('create', $claim); }
            catch (PluginException) { $result = new PaymentResult('unknown', $claim['payment_no'], $claim['amount_minor']); }
            $row = $this->apply($claim, $result, true);
        } else $row = $this->payments->find((int) $row['payment_id']);
        return $this->view($row);
    }

    public function detail(int $memberId, int $rechargeId): array { return $this->view($this->owned($memberId, $rechargeId)); }
    public function listing(int $memberId, int $page, int $size): array
    {
        $rows = $this->payments->listing($memberId, $page, $size);
        return ['data' => array_map($this->view(...), $rows['data']), 'total' => $rows['total']];
    }
    public function reconcile(int $memberId, int $rechargeId, bool $close = false): array
    {
        $row = $this->owned($memberId, $rechargeId);
        return $this->view($this->check((int) $row['payment_id'], $close));
    }
    public function tick(): void
    {
        foreach ($this->payments->due(20) as $id) $this->check($id, false, true);
    }
    /** 受控CLI核查付款单，不构造新上游付款请求。 */
    public function inspect(int $paymentId): array { return $this->view($this->check($paymentId)); }

    private function check(int $id, bool $close = false, bool $automatic = false): array
    {
        $row = $this->payments->claim($id, false, $automatic);
        if ($row === null) {
            $found = $this->payments->find($id);
            if ($found === null) throw new BusinessException('充值不存在', ApiErrorCode::NOT_FOUND, 404);
            return $found;
        }
        try {
            $result = $this->gateway->execute('query', $row);
            if ($result->status === 'pending' && ($close || $row['expires_at'] <= date('Y-m-d H:i:s'))) {
                $result = $this->gateway->execute('close', $row);
                // 关单失败可能与付款竞争，重新查单不能只按取消按钮判定。
                if ($result->status === 'unknown') $result = $this->gateway->execute('query', $row);
            }
        } catch (PluginException) { $result = new PaymentResult('unknown', $row['payment_no'], $row['amount_minor']); }
        return $this->apply($row, $result, false);
    }

    /** @param array<string, string>|PaymentNotification $notification @param array{client_ip: string, client_ip_location: string} $origin */
    public function notify(string $token, array|PaymentNotification $notification, string $rawBody, array $origin): string
    {
        $row = $this->payments->notification($token);
        if ($row === null) throw new BusinessException('支付通知无效', ApiErrorCode::NOT_FOUND, 404);
        $result = $this->gateway->verify($row, $notification);
        $source = $notification instanceof PaymentNotification ? json_decode($notification->body, true, 32, JSON_THROW_ON_ERROR)['id'] ?? '' : ($notification['notify_id'] ?? '');
        if ($source === '') $source = $result->paymentNo . ':' . $result->status . ':' . ($result->tradeNo ?? '');
        if (!is_string($source) || strlen($source) > 512) throw new PluginException('通知标识缺失', 'invalid_notification');
        $this->apply($row, $result, false, ['id' => hash('sha256', $source), 'content_hash' => hash('sha256', $rawBody)] + $origin);
        return $notification instanceof PaymentNotification ? 'json' : 'form';
    }

    private function apply(array $snapshot, PaymentResult $result, bool $creating, ?array $notification = null): array
    {
        if ($result->paymentNo !== $snapshot['payment_no'] || $result->amountMinor !== $snapshot['amount_minor']) throw new BusinessException('支付结果与原交易不匹配', ApiErrorCode::CONFLICT, 409);
        return $this->transactions->run(function () use ($snapshot, $result, $creating, $notification): array {
            $row = $this->payments->lock((int) $snapshot['payment_id']);
            if ($notification === null && $row['lease_token'] !== $snapshot['lease_token'] && $result->status !== 'paid') return $row;
            if ($row['status'] === 'paid' && $result->status === 'paid' && $row['channel_trade_no'] !== $result->tradeNo) throw new BusinessException('付款交易号不一致', ApiErrorCode::CONFLICT, 409);
            $next = OnlinePaymentState::next($row['status'], $result->status, $creating);
            $this->payments->update($row, $next, $row['status'] === 'paid' ? $row['channel_trade_no'] : $result->tradeNo, $result->qrCode, $next === 'unknown' ? 'needs_query' : null);
            if ($next === 'paid') $this->wallet->creditConfirmedRecharge((int) $row['member_id'], (int) $row['recharge_id']);
            if ($notification !== null) $this->payments->recordNotification($row, $notification);
            return $this->payments->find((int) $row['payment_id']);
        });
    }
    private function callbackBase(): string
    {
        $base = rtrim($this->settings->base()['site_url'], '/');
        $parts = parse_url($base);
        if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) return '';
        return $base;
    }
    private function owned(int $memberId, int $rechargeId): array
    {
        $row = $this->payments->recharge($memberId, $rechargeId);
        if ($row === null) throw new BusinessException('充值不存在', ApiErrorCode::NOT_FOUND, 404);
        return $row;
    }
    /** 只投影可公开的交易信息；不返回通道配置、回调标识、租约或商户敏感资料。 */
    private function view(array $row): array
    {
        return ['id' => (int) $row['recharge_id'], 'recharge_no' => $row['recharge_no'], 'payment_no' => $row['payment_no'],
            'amount_minor' => $row['amount_minor'], 'currency' => 'CNY', 'plugin_id' => $row['plugin_id'], 'status' => $row['status'],
            'expires_at' => $row['expires_at'], 'paid_at' => $row['paid_at'], 'created_at' => $row['created_at'],
            'qr_code' => $row['status'] === 'pending' && $row['expires_at'] > date('Y-m-d H:i:s') ? $row['qr_code'] : null,
            'message' => $row['last_error_code'] === 'manual_review' ? '支付结果需要进一步核查，请联系平台' : ($row['status'] === 'unknown' ? '支付结果待确认，请核查原交易' : '')];
    }
}
