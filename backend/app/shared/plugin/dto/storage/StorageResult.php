<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\storage;

use InvalidArgumentException;

/** 对象写入或删除结果，不泄露文件系统路径或平台报文。 */
final readonly class StorageResult
{
    /**
     * @param string $status stored/deleted/failed/unknown
     * @param ObjectKey $key 原逻辑对象键
     * @throws InvalidArgumentException 状态无效
     */
    public function __construct(public string $status, public ObjectKey $key)
    {
        if (!in_array($status, ['stored', 'deleted', 'failed', 'unknown'], true)) {
            throw new InvalidArgumentException('存储结果无效');
        }
    }
}
