<?php

declare(strict_types=1);

namespace app\modules\official_platform\domain;

/** 实例协议使用标准 UUID；随机标识不依赖旧版 UUID 库的启动兼容性。 */
final class InstanceUuid
{
    /** @return string 使用加密随机源生成 UUID v4 */
    public static function create(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * @param string $value 外部实例或连接标识
     * @return bool 校验 UUID 版本与标准变体
     */
    public static function isValid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value) === 1;
    }
}
