<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\captcha\CaptchaContext;

/** 受限的宿主图像验证码能力；不暴露 Redis、请求、容器或文件系统。 */
interface ImageCaptchaInterface
{
    /**
     * @param CaptchaContext $context 本次服务端上下文
     * @param int $length 验证字符数
     * @param int $width JPEG 宽度，px
     * @param int $height JPEG 高度，px
     * @return string JPEG data URI，答案只存宿主
     */
    public function generate(CaptchaContext $context, int $length, int $width, int $height): string;

    /**
     * @param CaptchaContext $context 原服务端上下文
     * @param string $answer 用户输入，不记录日志
     * @return bool 一次性验证；失败后必须重新获取
     */
    public function verify(CaptchaContext $context, string $answer): bool;
}
