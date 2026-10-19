<?php

declare(strict_types=1);

namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use support\validation\Validator;

/** 开发脚手架；只写固定类别，不覆盖已有插件或自动启用。 */
#[AsCommand('business-plugin:make-provider', '生成供应 SDK v1 最小插件骨架')]
final class MakeProviderPlugin extends Command
{
    /** @return void 固定开发参数 */
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, '小写稳定标识');
        $this->addArgument('name', InputArgument::REQUIRED, '客户可理解的插件名称');
    }

    /** @param InputInterface $input 参数 @param OutputInterface $output 生成说明 @return int 结果 */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $values = Validator::make(['id' => $input->getArgument('id'), 'name' => $input->getArgument('name')], [
            'id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/', 'name' => 'required|string|max:128',
        ])->validate();
        $target = base_path('plugins/provider/' . $values['id']);
        foreach (glob(base_path('plugins/*/' . $values['id'])) ?: [] as $existing) {
            $output->writeln('<error>此标识已有插件，不能覆盖或跨类别重复使用</error>');
            return self::FAILURE;
        }
        $template = base_path('resources/plugin-templates/provider');
        $manifest = json_decode(file_get_contents($template . '/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['id'] = $values['id'];
        $manifest['name'] = $values['name'];
        $manifest['version'] = '0.1.0';
        $manifest['publisher'] = '请填写开发者';
        // 端点、资源和配置只提供示例，开发者按真实协议修改。
        mkdir($target, 0755, true);
        file_put_contents($target . '/plugin.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        copy($template . '/configuration.schema.json', $target . '/configuration.schema.json');
        copy($template . '/LICENSE', $target . '/LICENSE');
        file_put_contents($target . '/ProviderAdapter.php', str_replace('__PLUGIN_ID__', $values['id'], file_get_contents($template . '/ProviderAdapter.php')));
        file_put_contents($target . '/README.md', "# 供应插件开发\n\n请修改配置、目录、端点及固定入口，补齐契约测试与目标平台联调。骨架方法未实现时会明确失败，不能作为可用渠道启用。\n");
        $output->writeln('<info>已创建骨架，请先完成固定方法及测试，然后运行 business-plugin:validate</info>');
        return self::SUCCESS;
    }
}
