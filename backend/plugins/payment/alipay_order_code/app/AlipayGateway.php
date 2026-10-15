<?php

declare(strict_types=1);

namespace plugins\payment\alipay_order_code\app;

use Alipay\EasySDK\Kernel\AlipayConstants;
use Alipay\EasySDK\Kernel\EasySDKKernel;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\exception\PluginException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** SDK 负责协议加签验签，公共 HTTP 负责 TLS、超时、响应限制；不使用 Tea 全局客户端。 */
final readonly class AlipayGateway
{
    /** @param HttpClientInterface $http 本次独立 HTTP 能力 */
    public function __construct(private HttpClientInterface $http)
    {
    }

    /**
     * @param Configuration $configuration 规范化的商户配置
     * @param string $method 插件内部固定 API 名称
     * @param array<string, mixed> $business 本次业务参数
     * @param array<string, string> $text 固定附加参数
     * @return array<string, mixed> 已验签的响应业务节点，不向宿主返回原始报文
     * @throws PluginException 结果未知、协议无效或验签失败
     */
    public function execute(Configuration $configuration, string $method, array $business, array $text = []): array
    {
        $config = AlipayConfiguration::sdk($configuration);
        $kernel = new EasySDKKernel($config);
        $system = $this->systemParameters($kernel, $method);
        $signature = $kernel->sign($system, $business, $text, $config->merchantPrivateKey);
        parse_str($kernel->generateOrderString($system, $business, $text, $signature), $parameters);
        try {
            $response = $this->http->postForm('https://' . $config->gatewayHost . '/gateway.do', $parameters);
        } catch (Throwable $error) {
            throw new PluginException('支付宝请求结果未知，请先查询原流水，避免重复发起', 'request_unknown', $error);
        }
        try {
            $payload = $response->json();
            $node = str_replace('.', '_', $method) . '_response';
            if (!$response->isSuccessful() || !is_array($payload[$node] ?? $payload['error_response'] ?? null)
                || (isset($payload[$node]) && isset($payload['error_response']))
                || !is_string($payload['sign'] ?? null) || base64_decode($payload['sign'], true) === false) {
                throw new PluginException('支付宝响应格式无效', 'invalid_response');
            }
            $map = [AlipayConstants::BODY_FIELD => $response->body, AlipayConstants::METHOD_FIELD => $method];
            if (!$kernel->verify($map, $config->alipayPublicKey)) {
                throw new PluginException('支付宝响应验签失败', 'invalid_response');
            }
            $result = $payload[$node] ?? $payload['error_response'];
            if (!is_string($result['code'] ?? null)) {
                throw new PluginException('支付宝响应状态无效', 'invalid_response');
            }
            return $result;
        } catch (PluginException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new PluginException('支付宝响应未通过协议验证', 'invalid_response', $error);
        }
    }

    /**
     * @param Configuration $configuration 本次配置
     * @param string $certifyId 已获取的认证流水
     * @return string SDK 生成的已签名认证链接
     */
    public function certificationUrl(Configuration $configuration, string $certifyId): string
    {
        $config = AlipayConfiguration::sdk($configuration);
        $kernel = new EasySDKKernel($config);
        $system = $this->systemParameters($kernel, 'alipay.user.certify.open.certify');
        $business = ['certify_id' => $certifyId];
        $signature = $kernel->sign($system, $business, [], $config->merchantPrivateKey);
        return $kernel->generatePage('GET', $system, $business, [], $signature);
    }

    /**
     * @param EasySDKKernel $kernel 本次 SDK 实例
     * @param string $method 固定方法
     * @return array<string, string> 统一 RSA2、UTF-8 及北京时间
     */
    private function systemParameters(EasySDKKernel $kernel, string $method): array
    {
        return [
            'method' => $method,
            'app_id' => $kernel->getConfig('appId'),
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
            'format' => 'json',
            'version' => '1.0',
            'alipay_sdk' => $kernel->getSdkVersion(),
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
        ];
    }
}
