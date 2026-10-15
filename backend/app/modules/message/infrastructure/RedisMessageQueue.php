<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure;

use app\modules\message\contract\MessageQueueInterface;
use Webman\RedisQueue\Redis;

final class RedisMessageQueue implements MessageQueueInterface
{
    public const QUEUE = 'howstack.message.delivery';

    public function __construct(private readonly string $connection = 'default')
    {
    }

    public function publish(int $deliveryId): bool
    {
        return Redis::connection($this->connection)->send(self::QUEUE, ['delivery_id' => $deliveryId]);
    }
}
