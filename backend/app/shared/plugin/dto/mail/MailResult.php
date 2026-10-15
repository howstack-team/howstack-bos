<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\mail;

use InvalidArgumentException;

/** 固定安全结果；禁止夹带外部平台原始回复、账号或正文。 */
final readonly class MailResult
{
    /**
     * @param string $status accepted、failed 或 unknown
     * @param string $messageId 本机生成的邮件标识
     */
    public function __construct(public string $status, public string $messageId)
    {
        if (!in_array($status, ['accepted', 'failed', 'unknown'], true)
            || !preg_match('/^<[a-f0-9-]{36}@howstack\.local>$/D', $messageId)) {
            throw new InvalidArgumentException('邮件发送结果无效');
        }
    }

    /**
     * @return array{status: string, message_id: string, message: string} 可向后台展示的安全结果
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'message_id' => $this->messageId, 'message' => match ($this->status) {
            'accepted' => '邮件服务器已接收，请到收件邮箱确认；接收成功不代表最终送达。',
            'failed' => '发送失败，请检查通道配置、平台凭据及发件信息。',
            'unknown' => '发送结果未知，服务器可能已接收，请先确认邮箱或服务器记录，避免重复发送。',
        }];
    }
}
