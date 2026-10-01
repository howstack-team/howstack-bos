<?php

declare(strict_types=1);

namespace app\shared\contract;

/** 持久化待投递事件；必须由业务用例在同一事务内显式调用。 */
interface OutboxWriterInterface
{
    /** @param array<string, int|string|bool|null> $payload 受控事件载荷 */
    public function append(string $key, string $type, int $aggregateId, array $payload): void;
}
