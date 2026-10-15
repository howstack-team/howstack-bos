<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\template\{TemplateSubmission, TemplateSubmissionResult};

/** 短信或邮件入口按需实现的模板提交能力；宿主负责权限、版本绑定和持久化幂等。 */
interface SubmitTemplateAdapterInterface
{
    /**
     * 向本次配置对应的平台账号提交模板，禁止发送真实短信或邮件。
     * @param TemplateSubmission $template 已校验的模板快照及稳定请求键
     * @param Configuration $configuration 原通道配置，不能跨调用保存
     * @return TemplateSubmissionResult accepted 仅表示受理，不表示审核通过；超时应返回 unknown
     */
    public function submitTemplate(TemplateSubmission $template, Configuration $configuration): TemplateSubmissionResult;
}
