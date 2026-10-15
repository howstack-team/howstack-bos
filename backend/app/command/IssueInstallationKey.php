<?php

declare(strict_types=1);

namespace app\command;

use app\modules\install\application\InstallationAuthorizationService;
use app\modules\install\exception\InstallationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('install:key', '生成一小时有效的在线安装授权码；已安装系统拒绝生成')]
final class IssueInstallationKey extends Command
{
    public function __construct(private readonly InstallationAuthorizationService $authorization)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $key = $this->authorization->issue();
        } catch (InstallationException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('<info>安装授权码（仅本次显示，一小时有效，重新生成会使旧码及旧授权失效）：</info>');
        $output->writeln($key);
        return self::SUCCESS;
    }
}
