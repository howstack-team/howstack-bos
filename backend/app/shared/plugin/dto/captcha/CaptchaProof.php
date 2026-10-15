<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\captcha;

use app\shared\plugin\exception\PluginException;
use JsonSerializable;
use LogicException;

/** 原样保留前端证明；不得写入日志或持久化任务。 */
final readonly class CaptchaProof implements JsonSerializable
{
    /** @param array<string, string> $values 平台规定的有限证明字段 */
    public function __construct(public array $values)
    {
        if (count($values) > 8) {
            throw new PluginException('验证证明字段过多', 'invalid_request');
        }
        $size = 0;
        foreach ($values as $key => $value) {
            if (!is_string($key) || !is_string($value) || strlen($key) > 64 || str_contains($value, chr(0))) {
                throw new PluginException('验证证明格式无效', 'invalid_request');
            }
            $size += strlen($value);
        }
        if ($size > 32768) {
            throw new PluginException('验证证明过长', 'invalid_request');
        }
    }

    /**
     * @param string $key 固定字段
     * @param int $max 最大字节数
     * @return string 原始证明，禁止改写平台参数
     * @throws PluginException 证明缺失或过长
     */
    public function text(string $key, int $max = 4096): string
    {
        $value = $this->values[$key] ?? '';
        if ($value === '' || strlen($value) > $max) {
            throw new PluginException('验证证明缺失或无效', 'invalid_request');
        }
        return $value;
    }

    /** @return array{redacted: true} 日志脱敏 */
    public function jsonSerialize(): array
    {
        return ['redacted' => true];
    }

    /** @return array{redacted: true} 调试脱敏 */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }

    /** @return never 禁止保存验证码答案及平台票据 */
    public function __serialize(): array
    {
        throw new LogicException('验证码证明不得序列化');
    }
}
