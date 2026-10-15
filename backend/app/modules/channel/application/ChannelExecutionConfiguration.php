<?php

declare(strict_types=1);

namespace app\modules\channel\application;

use app\exception\BusinessException;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\Configuration;

/** 宿主内部执行边界；调用方须完成对象权限、锁定和幂等预留，不向浏览器开放。 */
final readonly class ChannelExecutionConfiguration
{
    /**
     * @param ChannelRepositoryInterface $channels 通道持久化
     */
    public function __construct(private ChannelRepositoryInterface $channels)
    {
    }

    /**
     * @param string $category 固定类别
     * @param int $channelId 任务封存的通道
     * @param int $revision 任务封存的修订
     * @param bool $newRequest 新交易必须启用
     * @return array{string, Configuration} 插件身份及本次配置
     */
    public function resolve(string $category, int $channelId, int $revision, bool $newRequest): array
    {
        $row = $this->channels->find($category, $channelId);
        if ($row['revision'] !== $revision || !$row['configuration_current'] || !$row['platform_available']
            || ($newRequest && $row['status'] !== 1)) {
            throw new BusinessException('通道配置已变化或不可用，请先处理原业务流水', ApiErrorCode::CONFLICT, 409);
        }
        return [$row['plugin_id'], new Configuration($this->channels->configuration($category, $channelId))];
    }
}
