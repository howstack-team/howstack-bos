<?php

declare(strict_types=1);

namespace plugins\storage\tencent_cos;

use app\shared\plugin\contract\StorageAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\dto\storage\StorageResult;
use app\shared\plugin\dto\storage\StorageUrl;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use Throwable;
use Qcloud\Cos\Signature;
use GuzzleHttp\Psr7\Request;

/** 小对象写入、删除与限时读取；不开放文件路径或任意 SDK 方法。 */
final readonly class StorageAdapter implements StorageAdapterInterface
{
    /**
     * @param HttpClientInterface $http 宿主 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        $bucket = Fields::text($v, 'bucket', 63);
        if (!preg_match('/^[a-z0-9][a-z0-9-]*-[0-9]{5,20}$/D', $bucket)) {
            throw new PluginException('存储桶名称无效', 'invalid_configuration');
        }
        return new Configuration([
            'access_key_id' => Fields::text($v, 'access_key_id'),
            'access_key_secret' => Fields::text($v, 'access_key_secret'),
            'region' => Fields::region($v), 'bucket' => $bucket,
        ]);
    }

    /** @inheritDoc */
    public function put(ObjectUpload $object, Configuration $configuration): StorageResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $headers = ['Content-Type' => $object->contentType, 'Content-MD5' => base64_encode(md5($object->content, true))];
        return $this->execute('PUT', $object->key, $c, $headers, $object->content);
    }

    /** @inheritDoc */
    public function delete(ObjectKey $key, Configuration $configuration): StorageResult
    {
        return $this->execute('DELETE', $key, $this->validateConfiguration($configuration)->values, [], '');
    }

    /** @inheritDoc */
    public function signedUrl(ObjectKey $key, int $seconds, Configuration $configuration): StorageUrl
    {
        StorageUrl::validateLifetime($seconds);
        $c = $this->validateConfiguration($configuration)->values;
        $url = $this->url($c, $key);
        $signature = $this->signer($c)->createAuthorization(new Request('GET', $url), '+' . $seconds . ' seconds');
        return new StorageUrl($key, $url . '?' . $signature, time() + $seconds);
    }

    /**
     * @param array<string, string> $c 本次配置
     * @param ObjectKey $key 对象键
     * @return string 固定官方地址
     */
    private function url(array $c, ObjectKey $key): string
    {
        return 'https://' . $c['bucket'] . '.cos.' . $c['region'] . '.myqcloud.com/' . $key->encoded();
    }

    /**
     * @param array<string, string> $c 本次凭据
     * @return Signature 官方签名器，不改变常驻进程时区
     */
    private function signer(array $c): Signature
    {
        return new Signature($c['access_key_id'], $c['access_key_secret'], ['signHost' => true, 'timezone' => date_default_timezone_get()]);
    }

    /**
     * @param string $method 固定操作
     * @param ObjectKey $key 对象键
     * @param array<string, string> $c 配置
     * @param array<string, string> $headers 头部
     * @param string $body 正文
     * @return StorageResult 安全结果
     */
    private function execute(string $method, ObjectKey $key, array $c, array $headers, string $body): StorageResult
    {
        $url = $this->url($c, $key);
        $headers['Authorization'] = $this->signer($c)->createAuthorization(new Request($method, $url, $headers, $body), '+5 minutes');
        try {
            $reply = $this->http->request($method, $url, ['headers' => $headers, 'body' => $body]);
            $status = $reply->isSuccessful() ? ($method === 'PUT' ? 'stored' : 'deleted')
                : ($method === 'DELETE' && $reply->status === 404 ? 'deleted' : ($reply->status < 500 ? 'failed' : 'unknown'));
            return new StorageResult($status, $key);
        } catch (Throwable) {
            return new StorageResult('unknown', $key);
        }
    }
}
