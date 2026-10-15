<?php

declare(strict_types=1);

namespace app\process;

use app\modules\message\application\DispatchMessageService;
use support\Container;
use support\Log;
use Workerman\Timer;
use Throwable;

/** 补投数据库Outbox；恢复租约不盲重发外部结果未知的任务。 */
final class MessageDeliveryWorker
{
    public function onWorkerStart(): void
    {
        Timer::add(10, static function (): void {
            if (strtolower((string) getenv('APP_INSTALLED')) !== 'true') {
                return;
            }
            try {
                Container::get(DispatchMessageService::class)->tick();
            } catch (Throwable) {
                Log::warning('消息任务维护失败，请检查数据库连接与消息迁移状态');
            }
        });
    }
}
