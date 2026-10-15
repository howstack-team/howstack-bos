<?php

declare(strict_types=1);

namespace app\queue\redis;

use app\modules\message\application\DispatchMessageService;
use app\modules\message\infrastructure\RedisMessageQueue;
use support\Container;
use Webman\RedisQueue\Consumer;

/** Redis只携带任务ID，消费者异常日志不会包含收件人或消息正文。 */
final class MessageDeliveryConsumer implements Consumer
{
    public string $queue = RedisMessageQueue::QUEUE;
    public string $connection = 'default';

    public function consume(mixed $data): void
    {
        if (!is_array($data) || !is_int($data['delivery_id'] ?? null) || $data['delivery_id'] < 1) {
            return;
        }
        Container::get(DispatchMessageService::class)->consume($data['delivery_id']);
    }
}
