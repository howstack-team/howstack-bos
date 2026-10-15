<?php

declare(strict_types=1);

namespace tests\integration;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\payment\PaymentNotification;
use app\shared\plugin\dto\payment\PaymentRequest;
use app\shared\plugin\exception\PluginException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use plugins\payment\wechat_native\PaymentAdapter;
use WeChatPay\Crypto\AesGcm;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;

require_once dirname(__DIR__) . '/support/MockChannelHttp.php';
\app\modules\plugin\infrastructure\PluginDependencyLoader::load(dirname(__DIR__, 2) . '/plugins/payment/wechat_native', 'plugins\\payment\\wechat_native\\');

/** 用临时 RSA 密钥、真实 SDK 加密及模拟网络验证支付协议，不是真实商户联调。 */
final class WechatNativeTest extends TestCase
{
    private Configuration $config;
    private string $platformPrivate = '';
    private string $merchantPublic;
    private PaymentRequest $request;

    protected function setUp(): void
    {
        $merchant = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($merchant, $private);
        $this->merchantPublic = openssl_pkey_get_details($merchant)['key'];
        $platform = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($platform, $this->platformPrivate);
        $this->config = new Configuration([
            'app_id' => 'wx1234567890abcdef', 'mch_id' => '1234567890',
            'merchant_serial' => str_repeat('A', 40), 'platform_serial' => 'PUB_KEY_ID_TEST',
            'private_key' => $private, 'platform_public_key' => openssl_pkey_get_details($platform)['key'],
            'api_v3_key' => str_repeat('s', 32),
        ]);
        $this->request = new PaymentRequest('PAY-1', 29900, '云服务器', 'https://merchant.example.test/notify');
    }

    /**
     * @param string $body 原文
     * @param int|null $timestamp 时间
     * @return array<string, string> 真实 RSA 签名头
     */
    private function headers(string $body, ?int $timestamp = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce = 'nonce';
        return [
            'Wechatpay-Timestamp' => $timestamp, 'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Serial' => 'PUB_KEY_ID_TEST',
            'Wechatpay-Signature' => Rsa::sign(Formatter::response($timestamp, $nonce, $body), Rsa::from($this->platformPrivate, Rsa::KEY_TYPE_PRIVATE)),
        ];
    }

    /**
     * @param array<string, mixed> $data 响应
     * @param int $status HTTP 状态
     * @return Response 已签名响应
     */
    private function reply(array $data, int $status = 200): Response
    {
        $body = $status === 204 ? '' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new Response($status, $this->headers($body), $body);
    }

    /**
     * @return array<string, mixed> 本机付款快照匹配的交易
     */
    private function transaction(): array
    {
        return ['appid' => 'wx1234567890abcdef', 'mchid' => '1234567890', 'out_trade_no' => 'PAY-1',
            'amount' => ['total' => 29900, 'currency' => 'CNY'], 'trade_state' => 'SUCCESS', 'transaction_id' => 'WX-TRADE'];
    }

    public function testNativeRequestAndResponseAreSignedAndQrUsesWechatScheme(): void
    {
        $http = \tests\support\MockChannelHttp::create([$this->reply(['code_url' => 'weixin://wxpay/bizpayurl?pr=token'])], $history);
        $result = (new PaymentAdapter($http))->create($this->request, $this->config);
        self::assertSame('pending', $result->status);
        self::assertSame('weixin://wxpay/bizpayurl?pr=token', $result->qrCode);
        $request = $history[0]['request'];
        $data = json_decode((string) $request->getBody(), true);
        self::assertSame(['total' => 29900, 'currency' => 'CNY'], $data['amount']);
        preg_match_all('/(mchid|serial_no|timestamp|nonce_str|signature)="([^"]+)"/', $request->getHeaderLine('Authorization'), $parts, PREG_SET_ORDER);
        $authorization = [];
        foreach ($parts as $part) {
            $authorization[$part[1]] = $part[2];
        }
        self::assertTrue(Rsa::verify(Formatter::request('POST', '/v3/pay/transactions/native', $authorization['timestamp'], $authorization['nonce_str'], (string) $request->getBody()), $authorization['signature'], Rsa::from($this->merchantPublic, Rsa::KEY_TYPE_PUBLIC)));
        self::assertCount(1, $history);
    }

    public function testSignedQueryChecksAllPaymentFieldsAndSupportsClose(): void
    {
        $valid = $this->transaction();
        $wrong = $valid;
        $wrong['amount']['total'] = 1;
        $http = \tests\support\MockChannelHttp::create([$this->reply($valid), $this->reply($wrong), $this->reply([], 204)], $history);
        $adapter = new PaymentAdapter($http);
        self::assertSame('paid', $adapter->query($this->request, $this->config)->status);
        self::assertSame('unknown', $adapter->query($this->request, $this->config)->status);
        self::assertSame('closed', $adapter->close($this->request, $this->config)->status);
    }

    public function testUnsignedErrorsAndMalformedQrAreNeverTrustedOrRetried(): void
    {
        $http = \tests\support\MockChannelHttp::create([
            new Response(404, [], '{"code":"ORDER_NOT_EXIST"}'),
            $this->reply(['code_url' => 'javascript:alert(1)']),
            new \RuntimeException('credentials must not leak'),
        ], $history);
        $adapter = new PaymentAdapter($http);
        self::assertSame('unknown', $adapter->query($this->request, $this->config)->status);
        self::assertSame('unknown', $adapter->create($this->request, $this->config)->status);
        self::assertSame('unknown', $adapter->create($this->request, $this->config)->status);
        self::assertCount(3, $history);
    }

    public function testNativeNotificationUsesOriginalBodyRsaAndAuthenticatedEncryption(): void
    {
        $body = $this->notification($this->transaction());
        $notification = new PaymentNotification($body, $this->headers($body));
        $result = (new PaymentAdapter())->verifyJsonNotification($notification, $this->request, $this->config);
        self::assertSame('paid', $result->status);
        self::assertSame('WX-TRADE', $result->tradeNo);
        self::assertSame(['redacted' => true], $notification->jsonSerialize());
    }

    public function testNotificationRejectsAlteredBodyExpiredSignatureWrongMerchantAndAmount(): void
    {
        $adapter = new PaymentAdapter();
        $body = $this->notification($this->transaction());
        $wrongMerchant = $this->transaction();
        $wrongMerchant['mchid'] = '9999999999';
        $wrongAmount = $this->transaction();
        $wrongAmount['amount']['total'] = 299.00;
        $otherBodies = [$this->notification($wrongMerchant), $this->notification($wrongAmount)];
        $cases = [new PaymentNotification($body . ' ', $this->headers($body)), new PaymentNotification($body, $this->headers($body, time() - 600))];
        foreach ($otherBodies as $other) {
            $cases[] = new PaymentNotification($other, $this->headers($other));
        }
        $headers = $this->headers($body);
        $headers['Wechatpay-Serial'] = 'PUB_KEY_ID_OTHER';
        $cases[] = new PaymentNotification($body, $headers);
        foreach ($cases as $case) {
            try {
                $adapter->verifyJsonNotification($case, $this->request, $this->config);
                self::fail('非法付款通知不得通过');
            } catch (PluginException $error) {
                self::assertSame('invalid_notification', $error->reason);
            }
        }
    }

    public function testKeyInputRejectsFilePathsAndAcceptsSingleLinePem(): void
    {
        $adapter = new PaymentAdapter();
        $values = $this->config->values;
        foreach (['private_key', 'platform_public_key'] as $key) {
            $values[$key] = preg_replace('/\s+/', ' ', $values[$key]);
        }
        self::assertStringContainsString('-----BEGIN PRIVATE KEY-----', $adapter->validateConfiguration(new Configuration($values))->values['private_key']);
        foreach (['file:///etc/passwd', '/etc/passwd', 'file://merchant.pem'] as $path) {
            $values['private_key'] = $path;
            try {
                $adapter->validateConfiguration(new Configuration($values));
                self::fail('密钥配置不得读取服务器文件');
            } catch (PluginException $error) {
                self::assertSame('invalid_configuration', $error->reason);
            }
        }
    }

    /**
     * @param array<string, mixed> $transaction 已核对交易
     * @return string 官方 AES-GCM 结构
     */
    private function notification(array $transaction): string
    {
        $nonce = '0123456789ab';
        $aad = 'transaction';
        return json_encode(['event_type' => 'TRANSACTION.SUCCESS', 'resource' => [
            'original_type' => 'transaction', 'algorithm' => 'AEAD_AES_256_GCM', 'nonce' => $nonce,
            'associated_data' => $aad, 'ciphertext' => AesGcm::encrypt(json_encode($transaction, JSON_THROW_ON_ERROR), str_repeat('s', 32), $nonce, $aad),
        ]], JSON_THROW_ON_ERROR);
    }
}
