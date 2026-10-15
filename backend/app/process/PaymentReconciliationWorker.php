<?php

declare(strict_types=1);

namespace app\process;

use app\modules\finance\application\OnlineRechargeService;
use support\{Container, Log};
use Workerman\Timer;

final class PaymentReconciliationWorker
{
    public function onWorkerStart(): void
    {
        Timer::add(30, static function (): void {
            if (strtolower((string) getenv('APP_INSTALLED')) !== 'true') return;
            try { Container::get(OnlineRechargeService::class)->tick(); }
            catch (\Throwable) { Log::warning('支付查单维护未完成，请核对数据库、原支付插件和迁移状态'); }
        });
    }
}
