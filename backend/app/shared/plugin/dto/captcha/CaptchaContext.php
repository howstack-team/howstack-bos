<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\captcha;

use InvalidArgumentException;
use JsonSerializable;

/** 服务端确认的验证上下文；浏览器绑定和 IP 不由插件或提交表单选择。 */
final readonly class CaptchaContext implements JsonSerializable
{
    /**
     * @param string $id 本次随机挑战标识
     * @param string $browserBinding 服务端会话随机绑定值
     * @param string $purpose 业务场景标识
     * @param string $clientIp 可信代理处理后的客户端 IP
     */
    public function __construct(
        public string $id,
        public string $browserBinding,
        public string $purpose,
        public string $clientIp,
    ) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $browserBinding)
            || !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $purpose) || !filter_var($clientIp, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('人机验证上下文无效');
        }
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
}
