<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\payment;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** API v3 的原始正文与原始签名头，不重编码正文、不接受浏览器伪造的付款快照。 */
final readonly class PaymentNotification implements JsonSerializable
{
    /**
     * @var array<string, string> 单值小写签名头
     */
    public array $headers;

    /**
     * @param string $body 未修改的原始正文
     * @param array<string, string|list<string>> $headers 原始请求头
     */
    public function __construct(public string $body, array $headers)
    {
        if ($body === '' || strlen($body) > 2097152) {
            throw new InvalidArgumentException('付款通知正文无效');
        }
        $result = [];
        foreach ($headers as $name => $values) {
            $name = strtolower($name);
            if (!in_array($name, ['wechatpay-timestamp', 'wechatpay-nonce', 'wechatpay-serial', 'wechatpay-signature'], true)) {
                continue;
            }
            $value = is_array($values) && count($values) === 1 ? $values[0] : $values;
            if (isset($result[$name]) || !is_string($value) || $value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('付款通知签名头无效');
            }
            $result[$name] = $value;
        }
        if (count($result) !== 4) {
            throw new InvalidArgumentException('付款通知签名头缺失');
        }
        $this->headers = $result;
    }

    /**
     * @return array{redacted: true} 通知材料不写入 JSON 日志
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
     * @throws LogicException 不存储未验证的通知材料
     */
    public function __serialize(): array
    {
        throw new LogicException('付款通知不能序列化');
    }
}
