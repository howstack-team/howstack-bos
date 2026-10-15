<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\sms\SmsMessage;
use app\shared\plugin\dto\sms\SmsResult;

/** 短信 v1 发送契约；模板提交和审核查询按需额外实现 SubmitTemplateAdapterInterface/QueryTemplateReviewAdapterInterface。 */
interface SmsAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * @param SmsMessage $message 已授权的单收件人短信
     * @param Configuration $configuration 本次配置
     * @return SmsResult 平台受理结果，未知时禁止重发或切换通道
     */
    public function send(SmsMessage $message, Configuration $configuration): SmsResult;
}
