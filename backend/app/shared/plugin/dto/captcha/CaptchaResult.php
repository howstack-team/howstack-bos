<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\captcha;

use InvalidArgumentException;

/** 只有 verified 可继续业务；unknown 不得自动放行。 */
final readonly class CaptchaResult
{
    /** @param string $status verified/rejected/unknown */
    public function __construct(public string $status)
    {
        if (!in_array($status, ['verified', 'rejected', 'unknown'], true)) {
            throw new InvalidArgumentException('人机验证结果无效');
        }
    }
}
