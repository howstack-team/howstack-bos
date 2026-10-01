<?php

declare(strict_types=1);

namespace app\command;

use app\modules\admin\auth\application\PruneAdminLoginLogsService;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** 预览或执行管理员登录日志保留期清理。 */
#[AsCommand('admin:prune-login-logs', '预览或清理过期管理员登录日志')]
final class PruneAdminLoginLogs extends Command
{
    public function __construct(private readonly PruneAdminLoginLogsService $logs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, '保留天数（30-3650）', '180');
        $this->addOption('execute', null, InputOption::VALUE_NONE, '确认执行删除；默认仅预览');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT);
        if ($days === false) {
            $output->writeln('<error>保留天数必须为整数</error>');
            return self::INVALID;
        }
        try {
            $preview = $this->logs->preview($days);
            $output->writeln('截止时间：' . $preview['cutoff']);
            $output->writeln('待清理记录：' . $preview['count']);
            if (!$input->getOption('execute')) {
                $output->writeln('当前仅预览；完成备份后使用 --execute 清理。');
                return self::SUCCESS;
            }
            $output->writeln('已清理记录：' . $this->logs->execute($preview['cutoff']));
            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::INVALID;
        } catch (Throwable) {
            $output->writeln('<error>登录日志清理失败，请检查服务端日志</error>');
            return self::FAILURE;
        }
    }
}
