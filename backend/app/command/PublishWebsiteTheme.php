<?php

declare(strict_types=1);

namespace app\command;

use app\modules\website\application\PublishWebsiteThemeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** 核心源码发布步骤，供部署与主题开发使用。 */
#[AsCommand('website:publish', '校验并发布当前官网主题的私有快照与版本化静态资源')]
final class PublishWebsiteTheme extends Command
{
    public function __construct(private readonly PublishWebsiteThemeService $publisher)
    {
        parent::__construct();
    }

    /** @return int 命令结果 */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // CLI 同样加载宿主静态路由；不读取数据库来动态注册主题页面。
        if (\Webman\Route::getRoutes() === []) \Webman\Route::load([config_path()]);
        $package = $this->publisher->publish();
        $output->writeln('<info>' . OutputFormatter::escape($package['id'] . ' ' . $package['version'] . ' 已发布，摘要：' . $package['digest']) . '</info>');
        return self::SUCCESS;
    }
}
