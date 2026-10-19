<?php

declare(strict_types=1);
namespace app\process;
use app\modules\service_instance\application\ProvisionPaidOrderService;
use support\{Container, Log};
use Workerman\Timer;
/** 持久任务表是事实来源，重启及租约超时后继续查单。 */
final class ServiceProvisioningWorker
{
    /** @return void 注册定时领取，每次最多五台，避免阻塞单进程过久 */
    public function onWorkerStart(): void
    {
        Timer::add(5, static function (): void {
            if (strtolower((string) getenv('APP_INSTALLED')) !== 'true') return;
            try { Container::get(ProvisionPaidOrderService::class)->tick(5); }
            catch (\Throwable) { Log::warning('云服务器开通任务执行未完成，请检查进程、迁移和供应插件状态'); }
        });
    }
}
