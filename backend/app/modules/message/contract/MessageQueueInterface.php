<?php

declare(strict_types=1);

namespace app\modules\message\contract;

interface MessageQueueInterface
{
    /** 同步确认Redis已接收；队列中只有整数任务ID。 */
    public function publish(int $deliveryId): bool;
}
