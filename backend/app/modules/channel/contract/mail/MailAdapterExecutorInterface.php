<?php

declare(strict_types=1);

namespace app\modules\channel\contract\mail;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;

/** 固定邮件插件执行入口。 */
interface MailAdapterExecutorInterface
{
    /**
     * @param string $pluginId 已验证插件标识
     * @param Configuration $configuration 单次秘密
     * @param MailMessage $message 邮件
     * @return MailResult 安全结果
     */
    public function send(string $pluginId, Configuration $configuration, MailMessage $message): MailResult;
}
