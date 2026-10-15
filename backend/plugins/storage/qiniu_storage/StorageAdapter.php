<?php

declare(strict_types=1);

namespace plugins\storage\qiniu_storage;

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
use GuzzleHttp\Psr7\MultipartStream;

/** 七牛 Kodo REST API；直接生成上传、管理和私有下载签名，不依赖七牛 SDK。 */
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
        return new Configuration([
            'access_key' => Fields::text($v, 'access_key', 128),
            'secret_key' => Fields::text($v, 'secret_key', 128),
            'bucket' => Fields::text($v, 'bucket', 128),
            'region' => Fields::choice($v, 'region', ['z0', 'z1', 'z2', 'na0', 'as0']),
            'download_base' => Fields::httpsBase($v, 'download_base'),
        ]);
    }

    /** @inheritDoc */
    public function put(ObjectUpload $object, Configuration $configuration): StorageResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $policy = self::encode(json_encode([
            'scope' => $c['bucket'] . ':' . $object->key->value,
            'deadline' => time() + 300,
            'mimeLimit' => $object->contentType,
            'fsizeLimit' => ObjectUpload::MAX_BYTES,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $token = self::sign($policy, $c['access_key'], $c['secret_key']) . ':' . $policy;
        $stream = new MultipartStream([
            ['name' => 'token', 'contents' => $token], ['name' => 'key', 'contents' => $object->key->value],
            ['name' => 'file', 'contents' => $object->content, 'filename' => 'object', 'headers' => ['Content-Type' => $object->contentType]],
        ]);
        $host = $c['region'] === 'z0' ? 'upload.qiniup.com' : 'upload-' . $c['region'] . '.qiniup.com';
        try {
            $reply = $this->http->request('POST', 'https://' . $host . '/', [
                'body' => (string) $stream, 'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $stream->getBoundary()],
            ]);
            $data = $reply->json();
            $status = $reply->isSuccessful() && ($data['key'] ?? null) === $object->key->value ? 'stored'
                : (!$reply->isSuccessful() && $reply->status < 500 ? 'failed' : 'unknown');
            return new StorageResult($status, $object->key);
        } catch (Throwable) {
            return new StorageResult('unknown', $object->key);
        }
    }

    /** @inheritDoc */
    public function delete(ObjectKey $key, Configuration $configuration): StorageResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $path = '/delete/' . self::encode($c['bucket'] . ':' . $key->value);
        $url = 'https://rs.qiniu.com' . $path;
        $canonical = 'POST ' . $path . "\nHost: rs.qiniu.com\nContent-Type: application/x-www-form-urlencoded\n\n";
        try {
            $reply = $this->http->request('POST', $url, [
                'body' => '', 'headers' => [
                    'Authorization' => 'Qiniu ' . self::sign($canonical, $c['access_key'], $c['secret_key']),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);
            $status = $reply->isSuccessful() || $reply->status === 612 ? 'deleted' : ($reply->status < 500 ? 'failed' : 'unknown');
            return new StorageResult($status, $key);
        } catch (Throwable) {
            return new StorageResult('unknown', $key);
        }
    }

    /** @inheritDoc */
    public function signedUrl(ObjectKey $key, int $seconds, Configuration $configuration): StorageUrl
    {
        StorageUrl::validateLifetime($seconds);
        $c = $this->validateConfiguration($configuration)->values;
        $expires = time() + $seconds;
        $url = $c['download_base'] . '/' . $key->encoded() . '?e=' . $expires;
        return new StorageUrl($key, $url . '&token=' . self::sign($url, $c['access_key'], $c['secret_key']), $expires);
    }
    /**
     * @param string $value 原始字节
     * @return string 保留填充的 URL 安全 Base64
     */
    private static function encode(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }

    /**
     * @param string $value 待签名原文
     * @param string $accessKey 本次账号
     * @param string $secretKey 本次密钥
     * @return string Kodo HMAC-SHA1 令牌
     */
    private static function sign(string $value, string $accessKey, string $secretKey): string
    {
        return $accessKey . ':' . self::encode(hash_hmac('sha1', $value, $secretKey, true));
    }
}
