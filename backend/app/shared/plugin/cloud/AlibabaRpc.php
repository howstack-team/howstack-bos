<?php

declare(strict_types=1);

namespace app\shared\plugin\cloud;

use AlibabaCloud\Dara\Model;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\exception\PluginException;
use Darabonba\OpenApi\Utils;

/** 阿里云 RPC 传输桥；SDK 模型与 SDK 签名，HTTP 不使用全局客户端或自动重试。 */
final readonly class AlibabaRpc
{
    /**
     * @param HttpClientInterface $http 单次请求能力
     */
    public function __construct(private HttpClientInterface $http)
    {
    }

    /**
     * @param string $host 插件写死的官方域名
     * @param string $version 插件写死的 API 版本
     * @param string $action 插件写死的方法
     * @param Model $model 官方 SDK 请求模型
     * @param string $accessKeyId 本次账号
     * @param string $secret 本次账号密钥
     * @return array<string, mixed> 本次业务节点，禁止直接返回浏览器
     * @throws PluginException 网络或格式错误
     */
    public function request(string $host, string $version, string $action, Model $model, string $accessKeyId, string $secret): array
    {
        if (!in_array($host, ['dysmsapi.aliyuncs.com', 'dm.aliyuncs.com', 'dm.ap-southeast-1.aliyuncs.com', 'dm.ap-southeast-2.aliyuncs.com', 'captcha.cn-shanghai.aliyuncs.com', 'captcha.ap-southeast-1.aliyuncs.com'], true)) {
            throw new PluginException('阿里云接口地址无效', 'invalid_request');
        }
        $parameters = $model->toMap() + [
            'Action' => $action,
            'Version' => $version,
            'Format' => 'JSON',
            'AccessKeyId' => $accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $parameters['Signature'] = Utils::getRPCSignature($parameters, 'POST', $secret);
        $response = $this->http->postForm('https://' . $host . '/', $parameters);
        $data = $response->json();
        if (!is_string($data['RequestId'] ?? null) || (!$response->isSuccessful() && !is_string($data['Code'] ?? null))) {
            throw new PluginException('阿里云响应无效', 'invalid_response');
        }
        return $data;
    }
}
