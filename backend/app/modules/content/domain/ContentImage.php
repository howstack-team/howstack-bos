<?php

declare(strict_types=1);

namespace app\modules\content\domain;

use app\shared\validation\PublicImage;

/** 正文图片复用跨模块公开图片格式校验。 */
final class ContentImage
{
    public const int MAX_BYTES = PublicImage::MAX_BYTES;

    /** @return array{mime: string, extension: string} 已确认的格式 */
    public static function validate(string $content, string $filename): array
    {
        return PublicImage::validate($content, $filename);
    }
}
