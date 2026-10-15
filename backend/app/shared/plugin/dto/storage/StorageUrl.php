<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\storage;

use app\shared\plugin\http\HttpsUrl;
use InvalidArgumentException;

/** 有时效的只读访问地址；不是文件公开可见的标记。 */
final readonly class StorageUrl
{
    /**
     * @param ObjectKey $key 原对象键
     * @param string $url HTTPS 云地址或宿主固定本地下载地址
     * @param int $expiresAt Unix 到期时间
     * @throws InvalidArgumentException 地址或期限无效
     */
    public function __construct(public ObjectKey $key, public string $url, public int $expiresAt)
    {
        if ((!HttpsUrl::valid($url) && !str_starts_with($url, '/api/public/v1/storage/local?'))
            || (str_contains($url, chr(92)) || preg_match('/[\x00-\x20\x7f]/', $url)) || $expiresAt <= 0) {
            throw new InvalidArgumentException('存储访问地址无效');
        }
    }

    /**
     * @param int $seconds 访问有效秒数
     * @return void
     * @throws InvalidArgumentException 超出 60~3600 秒
     */
    public static function validateLifetime(int $seconds): void
    {
        if ($seconds < 60 || $seconds > 3600) {
            throw new InvalidArgumentException('文件访问有效期应为 60~3600 秒');
        }
    }
}
