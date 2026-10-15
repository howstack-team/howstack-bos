<?php

declare(strict_types=1);

namespace plugins\storage\local_storage;

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
use app\shared\plugin\contract\LocalObjectStoreInterface;
use app\shared\plugin\http\UnavailableLocalObjectStore;

/** 文件系统访问由宿主执行；插件只能使用逻辑分区与对象键。 */
final readonly class StorageAdapter implements StorageAdapterInterface
{
    /**
     * @param HttpClientInterface $http 统一注入签名
     * @param LocalObjectStoreInterface $store 宿主受限存储能力
     */
    public function __construct(
        private HttpClientInterface $http = new HttpClient(),
        private LocalObjectStoreInterface $store = new UnavailableLocalObjectStore(),
    ) {
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $namespace = Fields::text($configuration->values, 'namespace', 64);
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $namespace)) {
            throw new PluginException('本地存储分区无效', 'invalid_configuration');
        }
        return new Configuration(['namespace' => $namespace]);
    }

    /** @inheritDoc */
    public function put(ObjectUpload $object, Configuration $configuration): StorageResult
    {
        $this->store->put($this->validateConfiguration($configuration)->values['namespace'], $object);
        return new StorageResult('stored', $object->key);
    }

    /** @inheritDoc */
    public function delete(ObjectKey $key, Configuration $configuration): StorageResult
    {
        $this->store->delete($this->validateConfiguration($configuration)->values['namespace'], $key);
        return new StorageResult('deleted', $key);
    }

    /** @inheritDoc */
    public function signedUrl(ObjectKey $key, int $seconds, Configuration $configuration): StorageUrl
    {
        return $this->store->signedUrl($this->validateConfiguration($configuration)->values['namespace'], $key, $seconds);
    }
}
