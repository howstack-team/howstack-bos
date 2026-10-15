<?php

declare(strict_types=1);

namespace app\modules\official_platform\contract;

/** 加密保存本安装实例状态；读取结果仅在后端固定用例内部传递。 */
interface OfficialPlatformStateStoreInterface
{
    /** @return array<string, mixed> 私有状态，不得直接返回 HTTP 或写入日志 */
    public function read(): array;

    /**
     * @param array<string, mixed> $state 持有串行锁时写入
     * @return void
     */
    public function write(array $state): void;

    /**
     * @template T
     * @param callable(): T $operation 跨 Worker 的短连接操作
     * @return T 调用结果
     */
    public function synchronized(callable $operation): mixed;
}
