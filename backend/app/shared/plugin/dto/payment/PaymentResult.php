<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\payment;

use InvalidArgumentException;

/** 安全付款结果；paid 只表示上游已验签并匹配快照，宿主仍须幂等入账。 */
final readonly class PaymentResult
{
    /**
     * @param string $status pending/paid/closed/not_found/failed/unknown
     * @param string $paymentNo 原付款单号
     * @param int $amountMinor 原应付金额，单位分
     * @param string|null $tradeNo 上游交易号
     * @param string|null $qrCode 付款二维码内容，无图片或 HTML
     */
    public function __construct(
        public string $status,
        public string $paymentNo,
        public int $amountMinor,
        public ?string $tradeNo = null,
        public ?string $qrCode = null,
    ) {
        if (!in_array($status, ['pending', 'paid', 'closed', 'not_found', 'failed', 'unknown'], true)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $paymentNo) || $amountMinor < 1
            || ($tradeNo !== null && !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $tradeNo))
            || ($status === 'paid' && $tradeNo === null)
            || ($qrCode !== null && (strlen($qrCode) > 2048 || (!PaymentRequest::validUrl($qrCode) && !preg_match('~^weixin://wxpay/bizpayurl\?pr=[A-Za-z0-9_-]{1,256}$~D', $qrCode))))) {
            throw new InvalidArgumentException('付款结果无效');
        }
    }
}
