<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\sms;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** 单收件人短信；模板号和参数由宿主已授权业务提供，不持久化正文或验证码 DTO。 */
final readonly class SmsMessage implements JsonSerializable
{
    /**
     * @param string $key 宿主唯一业务事件标识，不能替代宿主持久化幂等
     * @param string $recipient E.164 手机号
     * @param string $templateId 平台审核模板号；正文平台可为空
     * @param array<string|int, string> $parameters 模板变量，腾讯按声明顺序取值
     * @param string $text 已审核模板渲染出的完整正文，仅正文平台使用
     * @throws InvalidArgumentException 输入无效
     */
    public function __construct(
        public string $key,
        public string $recipient,
        public string $templateId = '',
        public array $parameters = [],
        public string $text = '',
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $recipient)
            || ($templateId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $templateId))
            || count($parameters) > 32 || mb_strlen($text) > 2048 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) {
            throw new InvalidArgumentException('短信输入无效');
        }
        foreach ($parameters as $name => $value) {
            if ((!is_int($name) && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name))
                || !is_string($value) || mb_strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('短信模板变量无效');
            }
        }
    }

    /**
     * @return array{redacted: true} 隐藏手机号、正文和变量
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
     * @throws LogicException 队列应保存业务引用
     */
    public function __serialize(): array
    {
        throw new LogicException('短信输入不能序列化，请保存业务事件引用');
    }
}
