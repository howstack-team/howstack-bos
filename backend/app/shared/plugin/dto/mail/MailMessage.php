<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\mail;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** 单收件人邮件；任务只能持久化通道引用与业务事件，不序列化正文。 */
final readonly class MailMessage implements JsonSerializable
{
    /**
     * @param string $key 调用方生成的 UUID 幂等键
     * @param string $recipient 收件邮箱
     * @param string $subject 邮件主题
     * @param string $text 纯文本正文
     * @param string $html 可选 HTML 正文
     */
    public function __construct(
        public string $key,
        public string $recipient,
        public string $subject,
        public string $text,
        public string $html = '',
    ) {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $key)
            || strlen($recipient) > 254 || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\x00-\x1f\x7f]/', $recipient . $subject)
            || trim($subject) === '' || mb_strlen($subject) > 200
            || $text === '' || strlen($text) > 262144 || strlen($html) > 262144) {
            throw new InvalidArgumentException('邮件标识、收件地址、主题或正文无效');
        }
    }

    /**
     * @return string 稳定 Message-ID；不能替代宿主幂等记录
     */
    public function messageId(): string
    {
        return '<' . $this->key . '@howstack.local>';
    }

    /**
     * @return array{redacted: true} 不输出收件地址或正文
     */
    public function jsonSerialize(): array
    {
        return ['redacted' => true];
    }

    /**
     * @return array{redacted: true} 调试脱敏
     */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }

    /**
     * @return never
     * @throws LogicException 不能持久化邮件正文
     */
    public function __serialize(): array
    {
        throw new LogicException('邮件正文不能序列化，请保存业务事件引用');
    }
}
