<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;

/** 邮件 v1 发送契约；模板提交和审核查询按需额外实现 SubmitTemplateAdapterInterface/QueryTemplateReviewAdapterInterface。 */
interface MailAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * 使用本次通道配置发送单封邮件，具体协议和第三方客户端由插件实现。
     * @param MailMessage $message 已验证的收件人、主题和正文
     * @param Configuration $configuration 本次通道配置，不得跨调用保存
     * @return MailResult 接收结果，不代表收件箱送达
     */
    public function send(MailMessage $message, Configuration $configuration): MailResult;
}
