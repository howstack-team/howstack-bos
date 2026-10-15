<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\template\{TemplateReviewReference, TemplateReviewResult};

/** 短信或邮件入口按需实现的只读审核查询能力，可与提交能力分别实现。 */
interface QueryTemplateReviewAdapterInterface
{
    /**
     * 查询原平台账号下的审核对象，不修改模板，也不隐式重新提交。
     * @param TemplateReviewReference $reference 原申请或模板引用，不是其他通道的模板 ID
     * @param Configuration $configuration 原通道配置，不能跨调用保存
     * @return TemplateReviewResult 统一审核状态；网络异常不能解释为驳回或不存在
     */
    public function queryTemplateReview(TemplateReviewReference $reference, Configuration $configuration): TemplateReviewResult;
}
