<?php

declare(strict_types=1);

namespace app\process;

use Workerman\Timer;

/** 安装在独立PHP子进程中执行，HTTPWorker不阻塞；部署必须保留此维护进程。 */
final class SoftwareMaintenanceWorker
{
    /** @var resource|null 最近CLI进程句柄 */
    private mixed $process = null;

    /** @return void 注册固定维护命令，每轮CLI持有本机全局安装锁 */
    public function onWorkerStart(): void
    {
        Timer::add(15, function (): void {
            if (strtolower((string) getenv('APP_INSTALLED')) !== 'true') {
                return;
            }
            if (is_resource($this->process)) {
                if (proc_get_status($this->process)['running']) {
                    return;
                }
                proc_close($this->process);
                $this->process = null;
            }
            $this->process = proc_open([PHP_BINARY, base_path('webman'), 'software:run'],
                [['pipe', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes, base_path());
            if (is_resource($this->process)) {
                fclose($pipes[0]);
            }
        });
    }
}
