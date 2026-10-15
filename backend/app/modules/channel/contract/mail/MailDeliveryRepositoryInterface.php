<?php

declare(strict_types=1);

namespace app\modules\channel\contract\mail;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;

/** 发送前原子领取；不在数据库事务内等待 SMTP。 */
interface MailDeliveryRepositoryInterface
{
    /**
     * @param int $channelId 邮件通道
     * @param int $revision 预期配置版本
     * @param MailMessage $message 邮件
     * @param int|null $testActor 测试管理员，NULL表示业务发送
     * @return array{configuration: Configuration|null, plugin_id: string, cached: MailResult|null} 仅新领取返回秘密配置
     */
    public function claim(int $channelId, int $revision, MailMessage $message, ?int $testActor): array;

    /**
     * @param string $key 幂等键
     * @param MailResult $result 安全结果
     * @return void 固定结果
     */
    public function finish(string $key, MailResult $result): void;

    /**
     * @return array{id: int, revision: int} 默认启用的邮件通道；未配置时明确失败，不随机切换
     */
    public function defaultChannel(): array;
}
