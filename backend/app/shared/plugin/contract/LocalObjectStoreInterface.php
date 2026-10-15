<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\dto\storage\StorageUrl;

/** 宿主向本地存储插件开放的受限能力；不提供根目录和任意绝对路径。 */
interface LocalObjectStoreInterface
{
    /**
     * @param string $namespace 通道命名空间
     * @param ObjectUpload $upload 已授权文件
     * @return void
     */
    public function put(string $namespace, ObjectUpload $upload): void;

    /**
     * @param string $namespace 原命名空间
     * @param ObjectKey $key 原对象键
     * @return void 删除已不存在对象也视为完成
     */
    public function delete(string $namespace, ObjectKey $key): void;

    /**
     * @param string $namespace 原命名空间
     * @param ObjectKey $key 原对象键
     * @param int $seconds 授权有效期
     * @return StorageUrl 固定宿主下载入口
     */
    public function signedUrl(string $namespace, ObjectKey $key, int $seconds): StorageUrl;
}
