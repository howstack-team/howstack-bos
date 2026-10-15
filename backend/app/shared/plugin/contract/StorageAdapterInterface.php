<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\dto\storage\StorageResult;
use app\shared\plugin\dto\storage\StorageUrl;

/** 存储 v1：固定小文件写入、删除和有时效的只读地址，不开放任意文件访问。 */
interface StorageAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * @param ObjectUpload $upload 已校验文件
     * @param Configuration $configuration 本次配置
     * @return StorageResult 写入结果
     */
    public function put(ObjectUpload $upload, Configuration $configuration): StorageResult;

    /**
     * @param ObjectKey $key 已核对归属的原对象键
     * @param Configuration $configuration 原存储配置
     * @return StorageResult 删除结果
     */
    public function delete(ObjectKey $key, Configuration $configuration): StorageResult;

    /**
     * @param ObjectKey $key 已授权对象键
     * @param int $seconds 有效期 60~3600 秒
     * @param Configuration $configuration 原存储配置
     * @return StorageUrl 有限期访问地址
     */
    public function signedUrl(ObjectKey $key, int $seconds, Configuration $configuration): StorageUrl;
}
