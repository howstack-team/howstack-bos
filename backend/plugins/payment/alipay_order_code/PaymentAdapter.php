<?php

declare(strict_types=1);

namespace plugins\payment\alipay_order_code;

use Alipay\EasySDK\Kernel\EasySDKKernel;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\contract\PaymentAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\payment\PaymentRequest;
use app\shared\plugin\dto\payment\PaymentResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use plugins\payment\alipay_order_code\app\AlipayConfiguration;
use plugins\payment\alipay_order_code\app\AlipayGateway;
use Throwable;

/** 支付宝订单码支付；只执行固定上游方法，不修改本系统订单及账务。 */
final readonly class PaymentAdapter implements PaymentAdapterInterface
{
    /** @param HttpClientInterface $http 公共 HTTP 能力，测试可注入 */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        return AlipayConfiguration::normalize($configuration);
    }

    /** @inheritDoc */
    public function create(PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $configuration = $this->configuration($request, $configuration);
        try {
            $response = (new AlipayGateway($this->http))->execute($configuration, 'alipay.trade.precreate', [
                'out_trade_no' => $request->paymentNo,
                'total_amount' => $request->decimalAmount(),
                'subject' => $request->subject,
                'timeout_express' => $request->timeoutMinutes . 'm',
                'seller_id' => $configuration->values['seller_id'],
            ], ['notify_url' => $request->notifyUrl]);
            if ($response['code'] !== '10000') {
                return $this->result($request, $response['code'] === '20000' ? 'unknown' : 'failed');
            }
            $qr = $response['qr_code'] ?? '';
            if (($response['out_trade_no'] ?? '') !== $request->paymentNo || !is_string($qr)
                || !PaymentRequest::validUrl($qr) || parse_url($qr, PHP_URL_HOST) !== 'qr.alipay.com') {
                throw new PluginException('支付宝付款二维码或订单对应关系无效', 'invalid_response');
            }
            return new PaymentResult('pending', $request->paymentNo, $request->amountMinor, qrCode: $qr);
        } catch (PluginException $error) {
            if (!in_array($error->reason, ['request_unknown', 'invalid_response'], true)) {
                throw $error;
            }
            return $this->result($request, 'unknown');
        }
    }

    /** @inheritDoc */
    public function query(PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $configuration = $this->configuration($request, $configuration);
        $response = (new AlipayGateway($this->http))->execute($configuration, 'alipay.trade.query', ['out_trade_no' => $request->paymentNo]);
        if ($response['code'] !== '10000') {
            return $this->result($request, ($response['sub_code'] ?? '') === 'ACQ.TRADE_NOT_EXIST' ? 'not_found' : 'unknown');
        }
        $this->match($response, $request, $configuration, false);
        return $this->tradeResult($response, $request);
    }

    /** @inheritDoc */
    public function close(PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $configuration = $this->configuration($request, $configuration);
        try {
            $response = (new AlipayGateway($this->http))->execute($configuration, 'alipay.trade.close', ['out_trade_no' => $request->paymentNo]);
            if ($response['code'] === '10000' && ($response['out_trade_no'] ?? '') === $request->paymentNo) {
                return $this->result($request, 'closed');
            }
            // 关闭失败可能是交易已经付款，交由宿主查单处理。
            return $this->result($request, 'unknown');
        } catch (PluginException $error) {
            if (!in_array($error->reason, ['request_unknown', 'invalid_response'], true)) {
                throw $error;
            }
            return $this->result($request, 'unknown');
        }
    }

    /** @inheritDoc */
    public function verifyNotification(array $parameters, PaymentRequest $request, Configuration $configuration): PaymentResult
    {
        $configuration = $this->configuration($request, $configuration);
        try {
            if (count($parameters) > 128 || ($parameters['sign_type'] ?? '') !== 'RSA2'
                || !is_string($parameters['sign'] ?? null) || base64_decode($parameters['sign'], true) === false) {
                throw new PluginException('支付通知格式无效', 'invalid_notification');
            }
            foreach ($parameters as $key => $value) {
                if (!is_string($key) || !is_string($value) || strlen($value) > 8192) {
                    throw new PluginException('支付通知格式无效', 'invalid_notification');
                }
            }
            $kernel = new EasySDKKernel(AlipayConfiguration::sdk($configuration));
            if (!$kernel->verifyParams($parameters, $configuration->values['alipay_public_key'])) {
                throw new PluginException('支付通知验签失败', 'invalid_notification');
            }
            $this->match($parameters, $request, $configuration, true);
            return $this->tradeResult($parameters, $request);
        } catch (PluginException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new PluginException('支付通知未通过验证', 'invalid_notification', $error);
        }
    }

    /**
     * @param PaymentRequest $request 付款快照
     * @param Configuration $configuration 当前配置
     * @return Configuration 已校验配置
     */
    private function configuration(PaymentRequest $request, Configuration $configuration): Configuration
    {
        if ($request->currency !== 'CNY') {
            throw new PluginException('支付宝订单码支付仅支持人民币', 'invalid_request');
        }
        return $this->validateConfiguration($configuration);
    }

    /**
     * @param array<string, mixed> $data 已验签节点
     * @param PaymentRequest $request 原付款快照
     * @param Configuration $configuration 商户配置
     * @param bool $notification 通知必须验证 app_id
     * @return void
     * @throws PluginException 不匹配不得入账
     */
    private function match(array $data, PaymentRequest $request, Configuration $configuration, bool $notification): void
    {
        $reason = $notification ? 'invalid_notification' : 'invalid_response';
        if (($data['out_trade_no'] ?? '') !== $request->paymentNo
            || (($notification || array_key_exists('seller_id', $data))
                && ($data['seller_id'] ?? '') !== $configuration->values['seller_id'])
            || ($notification && ($data['app_id'] ?? '') !== $configuration->values['app_id'])
            || !is_string($data['total_amount'] ?? null)) {
            throw new PluginException('付款身份或订单对应关系不匹配', $reason);
        }
        try {
            if (PaymentRequest::parseAmount($data['total_amount']) !== $request->amountMinor) {
                throw new PluginException('付款金额不匹配', $reason);
            }
        } catch (\InvalidArgumentException $error) {
            throw new PluginException('付款金额格式无效', $reason, $error);
        }
    }

    /**
     * @param array<string, mixed> $data 已匹配的交易节点
     * @param PaymentRequest $request 付款快照
     * @return PaymentResult 状态映射
     */
    private function tradeResult(array $data, PaymentRequest $request): PaymentResult
    {
        $status = match ($data['trade_status'] ?? '') {
            'WAIT_BUYER_PAY' => 'pending',
            'TRADE_SUCCESS', 'TRADE_FINISHED' => 'paid',
            'TRADE_CLOSED' => 'closed',
            default => 'unknown',
        };
        $trade = $data['trade_no'] ?? null;
        if ($trade !== null && (!is_string($trade) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $trade))) {
            throw new PluginException('支付宝交易号无效', 'invalid_response');
        }
        if ($status === 'paid' && $trade === null) {
            throw new PluginException('支付宝交易号缺失', 'invalid_response');
        }
        return new PaymentResult($status, $request->paymentNo, $request->amountMinor, $trade);
    }

    /**
     * @param PaymentRequest $request 原快照
     * @param string $status 结果状态
     * @return PaymentResult 安全结果
     */
    private function result(PaymentRequest $request, string $status): PaymentResult
    {
        return new PaymentResult($status, $request->paymentNo, $request->amountMinor);
    }
}
