<?php

declare(strict_types=1);

namespace app\modules\channel\application\storage;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\dto\storage\StorageResult;
use app\shared\plugin\dto\storage\StorageUrl;

/** 调用方完成对象权限、可信 MIME 检查和对象引用持久化；同一通道修订处理历史对象。 */
final readonly class StorageChannelService
{
    /**
     * @param ChannelExecutionConfiguration $configuration 配置边界
     * @param TransactionAdapterFactoryInterface $adapters 固定加载器
     */
    public function __construct(private ChannelExecutionConfiguration $configuration, private TransactionAdapterFactoryInterface $adapters)
    {
    }

    /**
     * @param int $channelId 已授权通道
     * @param int $revision 修订
     * @param ObjectUpload $object 已校验文件
     * @return StorageResult 写入结果
     */
    public function put(int $channelId, int $revision, ObjectUpload $object): StorageResult
    {
        [$id, $configuration] = $this->configuration->resolve('storage', $channelId, $revision, true);
        return $this->adapters->storage($id)->put($object, $configuration);
    }

    /**
     * @param int $channelId 原通道
     * @param int $revision 原修订
     * @param ObjectKey $key 已授权对象
     * @return StorageResult 删除结果
     */
    public function delete(int $channelId, int $revision, ObjectKey $key): StorageResult
    {
        [$id, $configuration] = $this->configuration->resolve('storage', $channelId, $revision, false);
        return $this->adapters->storage($id)->delete($key, $configuration);
    }

    /**
     * @param int $channelId 原通道
     * @param int $revision 原修订
     * @param ObjectKey $key 已授权对象
     * @param int $seconds 有效期
     * @return StorageUrl 限时读取地址
     */
    public function signedUrl(int $channelId, int $revision, ObjectKey $key, int $seconds = 300): StorageUrl
    {
        [$id, $configuration] = $this->configuration->resolve('storage', $channelId, $revision, false);
        return $this->adapters->storage($id)->signedUrl($key, $seconds, $configuration);
    }
}
