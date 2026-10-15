<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\template;

use InvalidArgumentException;

/** 提交受理结果独立于审核结论；unknown 不允许自动重新提交或切换账号。 */
final readonly class TemplateSubmissionResult
{
    /**
     * @param string $status accepted、failed 或 unknown
     * @param string|null $providerReference 厂商可查询的申请或模板 ID；accepted 时必填
     * @param string $reason 安全的失败说明，不得直接复制厂商原始报文、凭据或堆栈
     */
    public function __construct(public string $status, public ?string $providerReference = null, public string $reason = '')
    {
        if (!in_array($status, ['accepted', 'failed', 'unknown'], true)
            || ($providerReference !== null && !TemplateReviewReference::validId($providerReference))
            || ($status === 'accepted' && $providerReference === null)
            || mb_strlen($reason) > 1000 || preg_match('//u', $reason) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InvalidArgumentException('模板提交结果无效');
        }
    }
}
