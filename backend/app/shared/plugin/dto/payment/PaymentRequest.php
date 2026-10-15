<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\payment;

use app\shared\plugin\http\HttpsUrl;
use InvalidArgumentException;

/** 由宿主订单生成的付款快照，金额为整数分，付款单号同时用作上游幂等标识。 */
final readonly class PaymentRequest
{
    /**
     * @param string $paymentNo 宿主付款单号
     * @param int $amountMinor 应付金额，单位分
     * @param string $subject 商品标题
     * @param string $notifyUrl 宿主固定 HTTPS 通知地址
     * @param int $timeoutMinutes 付款有效期，单位分钟
     * @param string $currency 币种
     */
    public function __construct(
        public string $paymentNo,
        public int $amountMinor,
        public string $subject,
        public string $notifyUrl,
        public int $timeoutMinutes = 15,
        public string $currency = 'CNY',
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $paymentNo) || $amountMinor < 1 || $amountMinor > 99999999999
            || trim($subject) === '' || mb_strlen($subject) > 256 || $timeoutMinutes < 1 || $timeoutMinutes > 120
            || !preg_match('/^[A-Z]{3}$/D', $currency) || !self::validUrl($notifyUrl)) {
            throw new InvalidArgumentException('付款请求无效');
        }
    }

    /**
     * @return string 无浮点运算的元金额字符串
     */
    public function decimalAmount(): string
    {
        return intdiv($this->amountMinor, 100) . '.' . str_pad((string) ($this->amountMinor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param string $amount 上游元金额
     * @return int 整数分
     * @throws InvalidArgumentException 格式或范围无效
     */
    public static function parseAmount(string $amount): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?$/D', $amount, $parts)) {
            throw new InvalidArgumentException('付款金额格式无效');
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * @param string $url 宿主地址
     * @return bool 无凭据、片段及控制字符的 HTTPS URL
     */
    public static function validUrl(string $url): bool
    {
        return HttpsUrl::valid($url);
    }
}
