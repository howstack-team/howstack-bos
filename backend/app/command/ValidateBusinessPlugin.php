<?php

declare(strict_types=1);

namespace app\command;

use app\modules\plugin\contract\PluginPackageRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** 无数据库校验命令；清单、包内容、版本、接口和声明网络范围由注册器统一校验。 */
#[AsCommand('business-plugin:validate', '验证业务插件清单、包边界和入口，不更改安装状态')]
final class ValidateBusinessPlugin extends Command
{
    /** @param PluginPackageRegistryInterface $packages 宿主包注册器 */
    public function __construct(private readonly PluginPackageRegistryInterface $packages)
    {
        parent::__construct();
    }

    /** @return void 固定标识参数 */
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, '省略时校验全部本地包');
    }

    /** @param InputInterface $input 参数 @param OutputInterface $output 安全结果 @return int 校验结果 */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        $count = 0;
        $failed = false;
        foreach ($this->packages->failures() as $failure) {
            if ($id === null || $id === $failure['id']) {
                $failed = true;
                $count++;
                $output->writeln('<error>' . OutputFormatter::escape($failure['id'] . '：' . $failure['message']) . '</error>');
            }
        }
        foreach ($this->packages->packages() as $package) {
            if ($id === null || $id === $package['id']) {
                $count++;
                $output->writeln('<info>' . OutputFormatter::escape($package['id'] . '：结构与固定接口校验通过；仍需代码审核、契约测试及外部联调') . '</info>');
            }
        }
        if ($count === 0) {
            $output->writeln('<error>没有找到插件包</error>');
            return self::INVALID;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
