<?php

declare(strict_types=1);

namespace plugins\payment\wechat_native;

use app\shared\plugin\contract\JsonPaymentNotificationAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\payment\PaymentNotification;
use app\shared\plugin\dto\payment\PaymentRequest;
use app\shared\plugin\dto\payment\PaymentResult;
use app\shared\plugin\dto\HttpResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Crypto\AesGcm;
use WeChatPay\Formatter;
use Throwable;

/** API v3 Native，使用官方 SDK RSA/AES/格式化能力；每次响应验签，无自动重试。 */
final readonly class PaymentAdapter implements JsonPaymentNotificationAdapterInterface
{
    /**
     * @param HttpClientInterface $http 受限 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        $c = [
            'app_id' => Fields::text($v, 'app_id'), 'mch_id' => Fields::text($v, 'mch_id'),
            'merchant_serial' => Fields::text($v, 'merchant_serial'), 'platform_serial' => Fields::text($v, 'platform_serial'),
            'api_v3_key' => Fields::text($v, 'api_v3_key', 32),
        ];
        if (!preg_match('/^wx[a-f0-9]{16}$/D', $c['app_id']) || !preg_match('/^[0-9]{6,20}$/D', $c['mch_id'])
            || !preg_match('/^[A-F0-9]{16,64}$/D', $c['merchant_serial'])
            || !preg_match('/^(?:[A-F0-9]{16,64}|PUB_KEY_ID_[A-Za-z0-9_]{1,100})$/D', $c['platform_serial'])
            || strlen($c['api_v3_key']) !== 32) {
            throw new PluginException('微信支付商户配置无效', 'invalid_configuration');
        }
        foreach (['private_key', 'platform_public_key'] as $key) {
            $value = $v[$key] ?? null;
            if (!is_string($value) || strlen($value) > 2048 || str_contains($value, chr(0))) {
                throw new PluginException('微信支付密钥格式无效', 'invalid_configuration');
            }
            $value = $this->normalizeKey($value, $key === 'private_key');
            try {
                $parsed = Rsa::from($value, $key === 'private_key' ? Rsa::KEY_TYPE_PRIVATE : Rsa::KEY_TYPE_PUBLIC);
                $details = openssl_pkey_get_details($parsed);
                if (($details['bits'] ?? 0) !== 2048 || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                    throw new PluginException('微信支付要求 RSA 2048 位密钥', 'invalid_configuration');
                }
            } catch (Throwable) {
                throw new PluginException('微信支付密钥格式无效', 'invalid_configuration');
            }
            $c[$key] = $value;
        }
        return new Configuration($c);
    }

    /**
     * @param string $value PEM 或 Base64，禁止文件路径与 SDK URI 协议
     * @param bool $private 是否私钥
     * @return string 规范 PEM
     * @throws PluginException 密钥无效
     */
    private function normalizeKey(string $value, bool $private): string
    {
        $value = trim($value);
        $kind = $private ? 'PRIVATE KEY' : 'PUBLIC KEY';
        if (preg_match('/^-----BEGIN (PRIVATE KEY|RSA PRIVATE KEY|PUBLIC KEY|RSA PUBLIC KEY|CERTIFICATE)-----(.*?)-----END \\1-----$/sD', $value, $parts)) {
            $kind = $parts[1];
            $value = $parts[2];
        }
        $allowed = $private ? ['PRIVATE KEY', 'RSA PRIVATE KEY'] : ['PUBLIC KEY', 'RSA PUBLIC KEY', 'CERTIFICATE'];
        $encoded = preg_replace('/\s+/', '', $value);
        if (!in_array($kind, $allowed, true) || !preg_match('/^[A-Za-z0-9+\/=]+$/D', $encoded) || base64_decode($encoded, true) === false) {
            throw new PluginException('微信支付密钥格式无效', 'invalid_configuration');
        }
        return '-----BEGIN ' . $kind . "-----\n" . chunk_split($encoded, 64, "\n") . '-----END ' . $kind . '-----';
    }

    /** @inheritDoc */
    public function create(PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $this->validateRequest($request);
        try {
            $response = $this->call('POST', '/v3/pay/transactions/native', [
                'appid' => $c['app_id'], 'mchid' => $c['mch_id'], 'description' => $request->subject,
                'out_trade_no' => $request->paymentNo, 'notify_url' => $request->notifyUrl,
                'amount' => ['total' => $request->amountMinor, 'currency' => 'CNY'],
                'time_expire' => gmdate('Y-m-d\TH:i:s+00:00', time() + $request->timeoutMinutes * 60),
            ], $c);
            $data = $response->json();
            if ($response->isSuccessful() && is_string($data['code_url'] ?? null)) {
                return new PaymentResult('pending', $request->paymentNo, $request->amountMinor, qrCode: $data['code_url']);
            }
            return $this->result($request, $response->status < 500 && isset($data['code']) ? 'failed' : 'unknown');
        } catch (Throwable) {
            return $this->result($request, 'unknown');
        }
    }

    /** @inheritDoc */
    public function query(PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $this->validateRequest($request);
        try {
            $path = '/v3/pay/transactions/out-trade-no/' . rawurlencode($request->paymentNo) . '?mchid=' . $c['mch_id'];
            $response = $this->call('GET', $path, [], $c);
            $data = $response->json();
            if (!$response->isSuccessful()) {
                return $this->result($request, $response->status === 404 && ($data['code'] ?? '') === 'ORDER_NOT_EXIST' ? 'not_found' : 'unknown');
            }
            return $this->transaction($data, $request, $c);
        } catch (Throwable) {
            return $this->result($request, 'unknown');
        }
    }

    /** @inheritDoc */
    public function close(PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $this->validateRequest($request);
        try {
            $response = $this->call('POST', '/v3/pay/transactions/out-trade-no/' . rawurlencode($request->paymentNo) . '/close', ['mchid' => $c['mch_id']], $c);
            return $this->result($request, $response->status === 204 ? 'closed' : 'unknown');
        } catch (Throwable) {
            return $this->result($request, 'unknown');
        }
    }

    /** @inheritDoc */
    public function verifyNotification(array $parameters, PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        throw new PluginException('微信 API v3 使用 JSON 原始正文通知入口', 'invalid_notification');
    }

    /** @inheritDoc */
    public function verifyJsonNotification(PaymentNotification $notification, PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $this->validateRequest($request);
        try {
            $this->verify($notification->body, $notification->headers, $c);
            $data = json_decode($notification->body, true, 32, JSON_THROW_ON_ERROR);
            $resource = $data['resource'] ?? [];
            if (($data['event_type'] ?? '') !== 'TRANSACTION.SUCCESS' || ($resource['original_type'] ?? '') !== 'transaction'
                || ($resource['algorithm'] ?? '') !== 'AEAD_AES_256_GCM' || !is_string($resource['nonce'] ?? null)
                || strlen($resource['nonce']) !== 12 || !is_string($resource['associated_data'] ?? null)
                || !is_string($resource['ciphertext'] ?? null) || base64_decode($resource['ciphertext'], true) === false) {
                throw new PluginException('微信通知结构无效', 'invalid_notification');
            }
            $plaintext = AesGcm::decrypt($resource['ciphertext'], $c['api_v3_key'], $resource['nonce'], $resource['associated_data']);
            $result = $this->transaction(json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR), $request, $c);
            if ($result->status !== 'paid') {
                throw new PluginException('微信通知与付款快照不匹配', 'invalid_notification');
            }
            return $result;
        } catch (Throwable) {
            throw new PluginException('微信付款通知验证失败', 'invalid_notification');
        }
    }

    /**
     * @param PaymentRequest $request 付款快照
     * @return void
     * @throws PluginException 超出微信接口范围
     */
    private function validateRequest(PaymentRequest $request): void
    {
        if (strlen($request->paymentNo) > 32 || mb_strlen($request->subject) > 127 || $request->currency !== 'CNY' || $request->amountMinor > 2147483647) {
            throw new PluginException('微信付款参数超出允许范围', 'invalid_request');
        }
    }

    /**
     * @param string $method 固定方法
     * @param string $path 固定路径
     * @param array<string, mixed> $data 正文
     * @param array<string, string> $c 凭据
     * @return HttpResult 已验签响应
     */
    private function call(string $method, string $path, array $data, array $c): HttpResult
    {
        $body = $method === 'GET' ? '' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = Formatter::nonce();
        $signature = Rsa::sign(Formatter::request($method, $path, $timestamp, $nonce, $body), Rsa::from($c['private_key'], Rsa::KEY_TYPE_PRIVATE));
        $reply = $this->http->request($method, 'https://api.mch.weixin.qq.com' . $path, [
            'body' => $body, 'headers' => [
                'Content-Type' => 'application/json', 'Accept' => 'application/json',
                'Wechatpay-Serial' => $c['platform_serial'],
                'Authorization' => Formatter::authorization($c['mch_id'], $nonce, $signature, $timestamp, $c['merchant_serial']),
            ],
        ]);
        $headers = [];
        foreach ($reply->headers as $name => $values) {
            $name = strtolower($name);
            if (isset($headers[$name]) || count($values) !== 1) {
                throw new PluginException('微信响应头无效', 'invalid_response');
            }
            $headers[$name] = $values[0];
        }
        $this->verify($reply->body, $headers, $c);
        return $reply;
    }

    /**
     * @param string $body 原文
     * @param array<string, string> $headers 原始头
     * @param array<string, string> $c 信任锚
     * @return void
     * @throws PluginException 验签失败
     */
    private function verify(string $body, array $headers, array $c): void
    {
        $timestamp = $headers['wechatpay-timestamp'] ?? '';
        $nonce = $headers['wechatpay-nonce'] ?? '';
        $signature = $headers['wechatpay-signature'] ?? '';
        if (($headers['wechatpay-serial'] ?? '') !== $c['platform_serial'] || !preg_match('/^[0-9]{10}$/D', $timestamp)
            || abs(time() - (int) $timestamp) > 300 || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $nonce)
            || base64_decode($signature, true) === false
            || !Rsa::verify(Formatter::response($timestamp, $nonce, $body), $signature, Rsa::from($c['platform_public_key'], Rsa::KEY_TYPE_PUBLIC))) {
            throw new PluginException('微信支付响应验签失败', 'invalid_response');
        }
    }

    /**
     * @param array<string, mixed> $data 已验签交易
     * @param PaymentRequest $request 宿主快照
     * @param array<string, string> $c 商户配置
     * @return PaymentResult 核对结果
     */
    private function transaction(array $data, PaymentRequest $request, array $c): PaymentResult
    {
        if (($data['appid'] ?? null) !== $c['app_id'] || ($data['mchid'] ?? null) !== $c['mch_id']
            || ($data['out_trade_no'] ?? null) !== $request->paymentNo || ($data['amount']['total'] ?? null) !== $request->amountMinor
            || ($data['amount']['currency'] ?? null) !== 'CNY') {
            return $this->result($request, 'unknown');
        }
        $status = match ($data['trade_state'] ?? '') {
            'SUCCESS' => 'paid', 'NOTPAY', 'USERPAYING' => 'pending', 'CLOSED', 'REVOKED' => 'closed', default => 'unknown',
        };
        return new PaymentResult($status, $request->paymentNo, $request->amountMinor, $data['transaction_id'] ?? null);
    }

    /**
     * @param PaymentRequest $request 原快照
     * @param string $status 安全状态
     * @return PaymentResult 不夹带上游字段
     */
    private function result(PaymentRequest $request, string $status): PaymentResult
    {
        return new PaymentResult($status, $request->paymentNo, $request->amountMinor);
    }
}
