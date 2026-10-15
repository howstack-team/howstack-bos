<?php

declare(strict_types=1);

namespace app\shared\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 正文图片是公开素材；拒绝 SVG、伪扩展名和过大图片。 */
final class PublicImage
{
    public const int MAX_BYTES = 5242880;
    private const array TYPES = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/gif' => ['gif'], 'image/webp' => ['webp']];

    /** @return array{mime: string, extension: string} */
    public static function validate(string $content, string $filename): array
    {
        $size = strlen($content);
        $image = @getimagesizefromstring($content);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($size === 0 || $size > self::MAX_BYTES || $image === false
            || !isset(self::TYPES[$mime]) || ($image['mime'] ?? '') !== $mime
            || !in_array($extension, self::TYPES[$mime], true) || $image[0] * $image[1] > 16000000) {
            throw new BusinessException('请选择不超过5MiB、1600万像素的 JPG、PNG、GIF 或 WebP 图片', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['file' => ['图片文件无效或超出大小、类型及尺寸限制']]]);
        }
        return ['mime' => $mime, 'extension' => self::TYPES[$mime][0]];
    }
}
