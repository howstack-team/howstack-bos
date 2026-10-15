<?php

declare(strict_types=1);

namespace app\command;

use app\modules\software_maintenance\application\{RunInstallationJobService, ManageSoftwareUpdatesService};
use app\modules\licensing\application\ManageLicenseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** 固定受控CLI，仅执行数据库中已由后台授权受理的任务。 */
#[AsCommand('software:run', '执行已受理的软件维护任务并核对重启状态')]
final class RunSoftwareMaintenance extends Command
{
    /**
     * @param RunInstallationJobService $service 持久执行用例
     * @param ManageLicenseService $licenses 定期验证
     * @param ManageSoftwareUpdatesService $updates 后端定时检查
     */
    public function __construct(
        private readonly RunInstallationJobService $service,
        private readonly ManageLicenseService $licenses,
        private readonly ManageSoftwareUpdatesService $updates,
    ) {
        parent::__construct();
    }

    /**
     * @param InputInterface $input 输入
     * @param OutputInterface $output 输出
     * @return int 安全退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->licenses->refreshWhenDue();
            $this->service->tick();
            $this->updates->refreshWhenDue();
            return self::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>维护任务暂未执行，请查看任务状态或检查维护进程</error>');
            return self::FAILURE;
        }
    }
}
