<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\captcha;

use InvalidArgumentException;

/** 仅包含平台明确允许公开的初始化参数或本次 JPEG；不含服务端秘密。 */
final readonly class CaptchaChallenge
{
    /**
     * @param string $provider 固定插件 ID
     * @param array<string, string|int> $parameters 公开白名单初始化参数
     */
    public function __construct(public string $provider, public array $parameters)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $provider) || count($parameters) > 8) {
            throw new InvalidArgumentException('验证初始化参数无效');
        }
        foreach ($parameters as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException('验证初始化参数无效');
            }
        }
    }
}
