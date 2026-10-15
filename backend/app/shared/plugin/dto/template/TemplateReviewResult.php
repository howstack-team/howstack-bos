<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\template;

use InvalidArgumentException;

/** approved 才代表厂商确认通过；它不自动启用本系统消息配置。 */
final readonly class TemplateReviewResult
{
    /**
     * @param string $status pending、approved、rejected、not_found 或 unknown
     * @param string $providerReference 查询的原审核对象 ID
     * @param string|null $providerTemplateId 实际发送所用模板 ID；approved 时必填，允许与申请 ID 不同
     * @param string $reason 安全的驳回或状态说明，不包含原始报文和秘密
     */
    public function __construct(
        public string $status,
        public string $providerReference,
        public ?string $providerTemplateId = null,
        public string $reason = '',
    ) {
        if (!in_array($status, ['pending', 'approved', 'rejected', 'not_found', 'unknown'], true)
            || !TemplateReviewReference::validId($providerReference)
            || ($providerTemplateId !== null && !TemplateReviewReference::validId($providerTemplateId))
            || ($status === 'approved' && $providerTemplateId === null)
            || mb_strlen($reason) > 1000 || preg_match('//u', $reason) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InvalidArgumentException('模板审核结果无效');
        }
    }
}
