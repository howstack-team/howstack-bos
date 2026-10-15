<?php

declare(strict_types=1);

namespace app\command;

use app\modules\plugin\application\PluginLifecycleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** 受控本地包维护命令，不下载源码、运行 Shell 或接收任意文件路径。 */
#[AsCommand('business-plugin', '校验、登记、启停经过审核的本地业务插件')]
final class BusinessPlugin extends Command
{
    /**
     * 使用与后台相同的生命周期用例。
     * @param PluginLifecycleService $plugins 插件生命周期
     */
    public function __construct(private readonly PluginLifecycleService $plugins)
    {
        parent::__construct();
    }

    /** @return void 固定命令参数 */
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'list/install/enable/drain/disable/upgrade/uninstall', 'list');
        $this->addArgument('id', InputArgument::OPTIONAL, '固定插件标识');
    }

    /**
     * 执行受信任维护人员的明确动作。
     * @param InputInterface $input 命令参数
     * @param OutputInterface $output 安全输出
     * @return int 退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $action = (string) $input->getArgument('action');
            $rows = $this->plugins->list();
            if ($action === 'list') {
                foreach ($rows as $row) {
                    $output->writeln($row['id'] . ' | ' . $row['category'] . ' | ' . $row['version'] . ' | ' . $row['state'] . ' | ' . $row['diagnostic']);
                }
                return self::SUCCESS;
            }
            $id = (string) $input->getArgument('id');
            $row = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] === $id))[0] ?? null;
            if ($row === null || !in_array($action, ['install', 'enable', 'drain', 'disable', 'upgrade', 'uninstall'], true)) {
                $output->writeln('<error>请使用有效插件标识与固定生命周期动作</error>');
                return self::INVALID;
            }
            $result = $this->plugins->transition($id, $action, $row['revision']);
            $output->writeln('<info>插件状态：' . $result['state'] . '</info>');
            return self::SUCCESS;
        } catch (Throwable) {
            $output->writeln('<error>插件操作未完成，请在后台查看包诊断及业务引用</error>');
            return self::FAILURE;
        }
    }
}
