<?php

declare(strict_types=1);

namespace tests\integration;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\identity\IdentityReference;
use app\shared\plugin\dto\identity\IdentityRequest;
use app\shared\plugin\dto\payment\PaymentRequest;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use plugins\identity\alipay_identity\IdentityAdapter;
use plugins\payment\alipay_order_code\PaymentAdapter;

/** 真实官方 SDK 及 RSA2 加验签，HTTP 使用测试响应，不向外部平台发起交易。 */
final class AlipayAdaptersTest extends TestCase
{
    private string $merchantPrivate;
    private string $merchantPublic;
    private string $alipayPrivate;
    private string $alipayPublic;

    protected function setUp(): void
    {
        [$this->merchantPrivate, $this->merchantPublic] = $this->keyPair();
        [$this->alipayPrivate, $this->alipayPublic] = $this->keyPair();
    }

    public function testPrecreateUsesSignedExactAmountAndPerCallMerchant(): void
    {
        $history = [];
        $http = $this->http([
            $this->signed('alipay.trade.precreate', ['code' => '10000', 'out_trade_no' => 'PAY-1', 'qr_code' => 'https://qr.alipay.com/example']),
            $this->signed('alipay.trade.precreate', ['code' => '10000', 'out_trade_no' => 'PAY-1', 'qr_code' => 'https://qr.alipay.com/example']),
        ], $history);
        $adapter = new PaymentAdapter($http);
        self::assertSame('pending', $adapter->create($this->payment(), $this->configuration())->status);
        [$otherPrivate, $otherPublic] = $this->keyPair();
        $other = new Configuration([...$this->configuration()->values, 'app_id' => '2021000000000002', 'private_key' => $otherPrivate, 'sandbox' => true]);
        self::assertSame('pending', $adapter->create($this->payment(), $other)->status);
        foreach ($history as $index => $entry) {
            parse_str((string) $entry['request']->getBody(), $parameters);
            self::assertSame('alipay.trade.precreate', $parameters['method']);
            self::assertSame('RSA2', $parameters['sign_type']);
            self::assertSame($index === 0 ? '2021000000000001' : '2021000000000002', $parameters['app_id']);
            self::assertTrue($this->verifyForm($parameters, $index === 0 ? $this->merchantPublic : $otherPublic));
            $biz = json_decode($parameters['biz_content'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('299.01', $biz['total_amount']);
            self::assertSame('15m', $biz['timeout_express']);
            self::assertSame('2088000000000001', $biz['seller_id']);
            self::assertSame('https://merchant.example.test/payment/notify', $parameters['notify_url']);
            self::assertTrue($entry['options']['verify']);
            self::assertFalse($entry['options']['allow_redirects']);
            self::assertSame(10, $entry['options']['timeout']);
            self::assertSame($index === 0 ? 'openapi.alipay.com' : 'openapi-sandbox.dl.alipaydev.com', $entry['request']->getUri()->getHost());
        }
    }

    public function testQueryVerifiesAmountAndSupportsOfficialResponseWithoutSellerId(): void
    {
        $history = [];
        $base = ['code' => '10000', 'out_trade_no' => 'PAY-1', 'total_amount' => '299.01', 'trade_no' => 'ALI-1'];
        $responses = [];
        foreach (['WAIT_BUYER_PAY', 'TRADE_SUCCESS', 'TRADE_FINISHED', 'TRADE_CLOSED', 'unexpected'] as $status) {
            $responses[] = $this->signed('alipay.trade.query', [...$base, 'trade_status' => $status]);
        }
        $responses[] = $this->signed('alipay.trade.query', ['code' => '40004', 'sub_code' => 'ACQ.TRADE_NOT_EXIST']);
        $responses[] = $this->signed('alipay.trade.query', [...$base, 'total_amount' => '0.01', 'trade_status' => 'TRADE_SUCCESS']);
        $adapter = new PaymentAdapter($this->http($responses, $history));
        foreach (['pending', 'paid', 'paid', 'closed', 'unknown', 'not_found'] as $expected) {
            self::assertSame($expected, $adapter->query($this->payment(), $this->configuration())->status);
        }
        $this->expectException(PluginException::class);
        $adapter->query($this->payment(), $this->configuration());
    }

    public function testNotifyRejectsTamperingWrongAppSellerOrderAndAmount(): void
    {
        $adapter = new PaymentAdapter();
        $base = ['app_id' => '2021000000000001', 'seller_id' => '2088000000000001', 'out_trade_no' => 'PAY-1',
            'total_amount' => '299.01', 'trade_no' => 'ALI-1', 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'RSA2'];
        $valid = $this->notification($base);
        self::assertSame('paid', $adapter->verifyNotification($valid, $this->payment(), $this->configuration())->status);
        self::assertSame('paid', $adapter->verifyNotification($valid, $this->payment(), $this->configuration())->status);
        $invalid = [[...$valid, 'total_amount' => '399.01']];
        foreach (['app_id' => '2021000000000002', 'seller_id' => '2088000000000002', 'out_trade_no' => 'PAY-2',
            'total_amount' => '299.02', 'sign_type' => 'RSA', 'trade_no' => '@bad'] as $key => $value) {
            $invalid[] = $this->notification([...$base, $key => $value]);
        }
        $invalid[] = [...$valid, 'sign' => []];
        foreach ($invalid as $parameters) {
            try {
                $adapter->verifyNotification($parameters, $this->payment(), $this->configuration());
                self::fail('错误通知不得通过');
            } catch (PluginException $error) {
                self::assertStringNotContainsString($this->merchantPrivate, $error->getMessage());
            }
        }
    }

    public function testUnknownResultsNeverRetryAndCloseFailureRequiresQuery(): void
    {
        $history = [];
        $responses = [new ConnectException('fixture only', new Request('POST', 'https://openapi.alipay.com')),
            $this->signed('alipay.trade.precreate', ['code' => '10000', 'out_trade_no' => 'PAY-2', 'qr_code' => 'https://qr.alipay.com/example']),
            new Response(200, [], '{"alipay_trade_precreate_response":{"code":"10000"}}'),
            $this->signed('alipay.trade.precreate', ['code' => '40004', 'sub_msg' => 'private fixture response']),
            $this->signed('alipay.trade.close', ['code' => '10000', 'out_trade_no' => 'PAY-1']),
            $this->signed('alipay.trade.close', ['code' => '40004', 'sub_code' => 'ACQ.TRADE_STATUS_ERROR'])];
        $adapter = new PaymentAdapter($this->http($responses, $history));
        foreach (['unknown', 'unknown', 'unknown', 'failed'] as $expected) {
            self::assertSame($expected, $adapter->create($this->payment(), $this->configuration())->status);
        }
        self::assertSame('closed', $adapter->close($this->payment(), $this->configuration())->status);
        self::assertSame('unknown', $adapter->close($this->payment(), $this->configuration())->status);
        self::assertCount(6, $history);
    }

    public function testIdentityInitializeAndSignedQueryDoNotExposeMaterials(): void
    {
        $history = [];
        $responses = [$this->signed('alipay.user.certify.open.initialize', ['code' => '10000', 'certify_id' => 'CERT-1'])];
        foreach (['T', 'F', 'unexpected'] as $passed) {
            $responses[] = $this->signed('alipay.user.certify.open.query', ['code' => '10000', 'passed' => $passed,
                'identity_info' => 'private-identity', 'material_info' => 'private-face']);
        }
        $adapter = new IdentityAdapter($this->http($responses, $history));
        $request = new IdentityRequest('AUTH-1', '测试姓名', '11010519491231002X', 'https://merchant.example.test/identity/return');
        $result = $adapter->initialize($request, $this->configuration());
        self::assertSame('pending', $result->status);
        self::assertSame('CERT-1', $result->certifyId);
        parse_str(parse_url($result->url, PHP_URL_QUERY), $parameters);
        self::assertSame('alipay.user.certify.open.certify', $parameters['method']);
        self::assertSame(['certify_id' => 'CERT-1'], json_decode($parameters['biz_content'], true));
        self::assertTrue($this->verifyForm($parameters, $this->merchantPublic));
        parse_str((string) $history[0]['request']->getBody(), $parameters);
        self::assertTrue($this->verifyForm($parameters, $this->merchantPublic));
        $biz = json_decode($parameters['biz_content'], true);
        self::assertSame('FACE', $biz['biz_code']);
        self::assertSame('CERT_INFO', $biz['identity_param']['identity_type']);
        self::assertSame('11010519491231002X', $biz['identity_param']['cert_no']);
        self::assertSame($request->returnUrl, $biz['merchant_config']['return_url']);
        foreach (['verified', 'rejected', 'unknown'] as $expected) {
            $result = $adapter->query(new IdentityReference('AUTH-1', 'CERT-1'), $this->configuration());
            self::assertSame($expected, $result->status);
            self::assertStringNotContainsString('private-', json_encode($result));
        }
        self::assertSame('{"redacted":true}', json_encode($request));
        self::assertStringNotContainsString('11010519491231002X', print_r($request, true));
        $this->expectException(\LogicException::class);
        serialize($request);
    }

    public function testBadSignatureCannotConfirmIdentityAndPrivateKeyAcceptsPkcs8(): void
    {
        $history = [];
        $signed = $this->signed('alipay.user.certify.open.query', ['code' => '10000', 'passed' => 'F']);
        $tampered = str_replace('"F"', '"T"', (string) $signed->getBody());
        $adapter = new IdentityAdapter($this->http([new Response(200, [], $tampered)], $history));
        $config = $adapter->validateConfiguration($this->configuration());
        self::assertNotSame($this->merchantPrivate, $config->values['private_key']);
        self::assertSame('{"redacted":true}', json_encode($config));
        try {
            $adapter->query(new IdentityReference('AUTH-1', 'CERT-1'), $config);
            self::fail('篡改认证结果不得通过');
        } catch (PluginException $error) {
            self::assertSame('invalid_response', $error->reason);
        }
        $this->expectException(PluginException::class);
        $adapter->validateConfiguration(new Configuration([...$config->values, 'private_key' => 'invalid-key']));
    }

    /** @return PaymentRequest 测试快照 */
    private function payment(): PaymentRequest
    {
        return new PaymentRequest('PAY-1', 29901, '云服务器', 'https://merchant.example.test/payment/notify');
    }

    /** @return Configuration 仅测试密钥 */
    private function configuration(): Configuration
    {
        return new Configuration(['app_id' => '2021000000000001', 'seller_id' => '2088000000000001',
            'private_key' => $this->merchantPrivate, 'alipay_public_key' => $this->alipayPublic, 'sandbox' => false]);
    }

    /** @param array<object> $responses 测试响应 @param array $history 传输历史 @return HttpClient 真实请求编码配合 HTTP 桩 */
    private function http(array $responses, array &$history): HttpClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new HttpClient(new Client(['handler' => $stack]));
    }

    /** @param string $method 固定方法 @param array<string, mixed> $data 模拟平台业务节点 @return Response 真 RSA2 签名响应 */
    private function signed(string $method, array $data): Response
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        openssl_sign($body, $signature, $this->alipayPrivate, OPENSSL_ALGO_SHA256);
        return new Response(200, [], '{"' . str_replace('.', '_', $method) . '_response":' . $body . ',"sign":"' . base64_encode($signature) . '"}');
    }

    /** @param array<string, string> $parameters 本次通知 @return array<string, string> 加签通知 */
    private function notification(array $parameters): array
    {
        $copy = $parameters;
        unset($copy['sign'], $copy['sign_type']);
        ksort($copy);
        openssl_sign(urldecode(http_build_query($copy, '', '&', PHP_QUERY_RFC3986)), $signature, $this->alipayPrivate, OPENSSL_ALGO_SHA256);
        return [...$parameters, 'sign' => base64_encode($signature)];
    }

    /** @param array<string, string> $parameters 请求参数 @param string $public 验签公钥 @return bool 原请求协议签名匹配 */
    private function verifyForm(array $parameters, string $public): bool
    {
        $signature = base64_decode($parameters['sign']);
        unset($parameters['sign']);
        ksort($parameters);
        return openssl_verify(urldecode(http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)), $signature, $public, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return array{string, string} 随机临时测试 RSA 密钥 */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        return [$private, openssl_pkey_get_details($key)['key']];
    }
}
