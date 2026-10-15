<?php

declare(strict_types=1);

namespace plugins\storage\aliyun_oss;

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
use AlibabaCloud\Oss\V2\Client;
use AlibabaCloud\Oss\V2\Config;
use AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider;
use AlibabaCloud\Oss\V2\Models\PutObjectRequest;
use AlibabaCloud\Oss\V2\Models\DeleteObjectRequest;
use AlibabaCloud\Oss\V2\Models\GetObjectRequest;
use app\shared\plugin\http\SdkHttpHandler;
use GuzzleHttp\Psr7\Utils;
use DateInterval;

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
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $bucket)) {
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
        try {
            $this->client($c)->putObject(new PutObjectRequest(
                bucket: $c['bucket'], key: $object->key->value,
                body: Utils::streamFor($object->content), contentType: $object->contentType,
                contentMd5: base64_encode(md5($object->content, true)),
            ));
            return new StorageResult('stored', $object->key);
        } catch (Throwable) {
            return new StorageResult('unknown', $object->key);
        }
    }

    /** @inheritDoc */
    public function delete(ObjectKey $key, Configuration $configuration): StorageResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        try {
            $this->client($c)->deleteObject(new DeleteObjectRequest(bucket: $c['bucket'], key: $key->value));
            return new StorageResult('deleted', $key);
        } catch (Throwable) {
            return new StorageResult('unknown', $key);
        }
    }

    /** @inheritDoc */
    public function signedUrl(ObjectKey $key, int $seconds, Configuration $configuration): StorageUrl
    {
        StorageUrl::validateLifetime($seconds);
        $c = $this->validateConfiguration($configuration)->values;
        $result = $this->client($c)->presign(new GetObjectRequest(bucket: $c['bucket'], key: $key->value), [
            'expires' => new DateInterval('PT' . $seconds . 'S'),
        ]);
        return new StorageUrl($key, $result->url, $result->expiration->getTimestamp());
    }

    /**
     * @param array<string, string> $c 本次配置
     * @return Client 无重试、校验证书的 SDK 客户端
     */
    private function client(array $c): Client
    {
        $config = new Config();
        $config->setRegion($c['region']);
        $config->setEndpoint('https://oss-' . $c['region'] . '.aliyuncs.com');
        $config->setCredentialsProvider(new StaticCredentialsProvider($c['access_key_id'], $c['access_key_secret']));
        $config->setRetryMaxAttempts(1);
        $config->setConnectTimeout(4);
        $config->setReadwriteTimeout(10);
        $config->setEnabledRedirect(false);
        $config->setInsecureSkipVerify(false);
        return new Client($config, ['handler' => new SdkHttpHandler($this->http)]);
    }
}
