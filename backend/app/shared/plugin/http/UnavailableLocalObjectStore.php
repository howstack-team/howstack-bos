<?php

declare(strict_types=1);

namespace app\shared\plugin\http;

use app\shared\plugin\contract\LocalObjectStoreInterface;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\dto\storage\StorageUrl;
use app\shared\plugin\exception\PluginException;

/** 配置校验时的无副作用占位；执行必须由宿主注入实际文件能力。 */
final class UnavailableLocalObjectStore implements LocalObjectStoreInterface
{
    /** @inheritDoc */
    public function put(string $namespace, ObjectUpload $upload): void
    {
        throw new PluginException('本地存储能力未由宿主提供', 'plugin_unavailable');
    }

    /** @inheritDoc */
    public function delete(string $namespace, ObjectKey $key): void
    {
        throw new PluginException('本地存储能力未由宿主提供', 'plugin_unavailable');
    }

    /** @inheritDoc */
    public function signedUrl(string $namespace, ObjectKey $key, int $seconds): StorageUrl
    {
        throw new PluginException('本地存储能力未由宿主提供', 'plugin_unavailable');
    }
}
