<?php

declare(strict_types=1);

namespace app\shared\plugin\http;

use app\shared\plugin\contract\ImageCaptchaInterface;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\exception\PluginException;

/** 配置校验阶段无副作用占位；真实执行必须显式注入宿主能力。 */
final class UnavailableImageCaptcha implements ImageCaptchaInterface
{
    /** @inheritDoc */
    public function generate(CaptchaContext $context, int $length, int $width, int $height): string
    {
        throw new PluginException('图像验证码宿主能力不可用', 'plugin_unavailable');
    }

    /** @inheritDoc */
    public function verify(CaptchaContext $context, string $answer): bool
    {
        throw new PluginException('图像验证码宿主能力不可用', 'plugin_unavailable');
    }
}
