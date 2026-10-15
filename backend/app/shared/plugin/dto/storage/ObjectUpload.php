<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\storage;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** 已由宿主校验 MIME、大小及归属的单个小文件；不接受任意本地文件路径。 */
final readonly class ObjectUpload implements JsonSerializable
{
    public const int MAX_BYTES = 20971520;

    /**
     * @param ObjectKey $key 宿主生成的对象键
     * @param string $content 文件内容，最大 20 MiB
     * @param string $contentType 可信 MIME 类型
     * @throws InvalidArgumentException 内容或 MIME 无效
     */
    public function __construct(public ObjectKey $key, public string $content, public string $contentType)
    {
        if (strlen($content) > self::MAX_BYTES || !preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/D', $contentType)) {
            throw new InvalidArgumentException('文件内容或类型无效');
        }
    }

    /**
     * @return array{redacted: true} 文件内容脱敏
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
     * @throws LogicException 异步任务保存受保护的文件引用
     */
    public function __serialize(): array
    {
        throw new LogicException('文件内容不能序列化，请保存文件引用');
    }
}
