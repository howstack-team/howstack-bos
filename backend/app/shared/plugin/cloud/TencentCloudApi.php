<?php

declare(strict_types=1);

namespace app\shared\plugin\cloud;

use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\exception\PluginException;
use TencentCloud\Common\AbstractModel;
use TencentCloud\Common\Sign;

/** 腾讯云 TC3 传输桥；官方模型和 Sign，保持宿主 HTTP 约束且不持有凭据。 */
final readonly class TencentCloudApi
{
    /**
     * @param HttpClientInterface $http 单次 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http)
    {
    }

    /**
     * @param string $service 固定服务 sms/ses/faceid/captcha
     * @param string $version 固定 API 版本
     * @param string $action 插件固定方法
     * @param AbstractModel $model 官方 SDK 请求模型
     * @param string $secretId 本次账号
     * @param string $secretKey 本次密钥
     * @param string $region 本次已验证地域
     * @return array<string, mixed> 业务节点，仅供本次调用
     * @throws PluginException 网络或格式错误
     */
    public function request(string $service, string $version, string $action, AbstractModel $model, string $secretId, string $secretKey, string $region): array
    {
        if (!in_array($service, ['sms', 'ses', 'faceid', 'captcha'], true)) {
            throw new PluginException('腾讯云接口类别无效', 'invalid_request');
        }
        $host = $service . '.tencentcloudapi.com';
        $payload = $model->toJsonString();
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $scope = $date . '/' . $service . '/tc3_request';
        $canonical = "POST\n/\n\ncontent-type:application/json\nhost:" . $host . "\n\ncontent-type;host\n" . hash('sha256', $payload);
        $toSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $signature = Sign::signTC3($secretKey, $date, $service, $toSign);
        $response = $this->http->request('POST', 'https://' . $host . '/', ['body' => $payload, 'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $scope . ', SignedHeaders=content-type;host, Signature=' . $signature,
            'X-TC-Action' => $action,
            'X-TC-Version' => $version,
            'X-TC-Timestamp' => (string) $timestamp,
            ...($region !== '' ? ['X-TC-Region' => $region] : []),
        ]]);
        $data = $response->json()['Response'] ?? null;
        if (!is_array($data) || !is_string($data['RequestId'] ?? null)
            || (!$response->isSuccessful() && !is_array($data['Error'] ?? null))) {
            throw new PluginException('腾讯云响应无效', 'invalid_response');
        }
        return $data;
    }
}
