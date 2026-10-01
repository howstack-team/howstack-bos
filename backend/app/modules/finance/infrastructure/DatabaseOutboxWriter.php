<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\modules\finance\infrastructure\model\FinanceOutboxModel;
use app\shared\contract\OutboxWriterInterface;
use LogicException;

/** 沿用 finance_outbox，本轮只写记录，不投递。 */
final class DatabaseOutboxWriter implements OutboxWriterInterface
{
    public function append(string $key, string $type, int $aggregateId, array $payload): void
    {
        if ((new FinanceOutboxModel())->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Outbox 必须在业务事务中写入');
        }
        FinanceOutboxModel::query()->create([
            'event_key' => $key, 'event_type' => $type, 'aggregate_id' => $aggregateId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending', 'attempts' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
