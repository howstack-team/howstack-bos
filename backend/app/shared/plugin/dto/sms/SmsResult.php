<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\sms;

use InvalidArgumentException;

/** accepted 表示平台受理，不表示运营商最终送达。 */
final readonly class SmsResult
{
    /**
     * @param string $status accepted/failed/unknown
     * @param string $key 原宿主事件标识
     * @param string|null $receiptId 平台回执标识，不含手机号或正文
     * @throws InvalidArgumentException 状态或标识无效
     */
    public function __construct(public string $status, public string $key, public ?string $receiptId = null)
    {
        if (!in_array($status, ['accepted', 'failed', 'unknown'], true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key)
            || ($receiptId !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $receiptId))) {
            throw new InvalidArgumentException('短信结果无效');
        }
    }
}
