<?php

declare(strict_types=1);

namespace app\process;

use app\modules\service_instance\application\ProcessServiceLifecycleService;
use support\{Container, Log};
use Workerman\Timer;

/** 数据库任务为唯一事实来源，重启后自动续查，供应副作用不自动重发。 */
final class ServiceLifecycleWorker
{
    /** @return void 注册定时扫描及小批任务执行 */
    public function onWorkerStart(): void
    {
        Timer::add(10, static function (): void {
            if (strtolower((string) getenv('APP_INSTALLED')) !== 'true') {
                return;
            }
            try {
                Container::get(ProcessServiceLifecycleService::class)->tick(5);
            } catch (\Throwable $error) {
                Log::warning('服务续费与到期任务执行未完成', ['exception' => $error::class]);
            }
        });
    }
}
