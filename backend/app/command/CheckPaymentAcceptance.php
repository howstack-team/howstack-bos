<?php

declare(strict_types=1);

namespace app\command;

use app\exception\BusinessException;
use app\modules\finance\application\CheckPaymentAcceptanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** 不发起付款、不修改余额、不生成伪造的外部验收成功记录。 */
#[AsCommand('payment:check', '只读检查支付验收前置条件及单笔账务一致性')]
final class CheckPaymentAcceptance extends Command
{
    /**
     * 注入支付验收准备用例。
     * @param CheckPaymentAcceptanceService $checks 本地只读检查
     */
    public function __construct(private readonly CheckPaymentAcceptanceService $checks)
    {
        parent::__construct();
    }

    /** @return void 定义唯一可选的本地支付ID和JSON输出，不接受商户密钥或强制到账参数 */
    protected function configure(): void
    {
        $this->addArgument('payment-id', InputArgument::OPTIONAL, '后台支付记录内部ID；不传则仅检查配置')
            ->addOption('json', null, InputOption::VALUE_NONE, '输出脱敏的JSON报告');
    }

    /**
     * 输出只读检查结果。
     * @param InputInterface $input CLI参数
     * @param OutputInterface $output 终端输出
     * @return int 0仅表示本地准备检查通过；1未就绪/不一致；2参数无效，不代表发布放行
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument('payment-id');
        $id = $raw === null ? null : filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id !== null && $id < 1) {
            $output->writeln('请填写正确的后台支付记录ID');
            return self::INVALID;
        }
        try {
            $report = $this->checks->check($id);
        } catch (BusinessException $error) {
            $output->writeln($error->getMessage());
            return self::FAILURE;
        } catch (Throwable) {
            $output->writeln('支付预检未完成，请核对数据库、迁移和通道配置；未发起付款或修改账务');
            return self::FAILURE;
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln('本地支付预检：' . ($report['ready_for_external_test'] ? '配置已具备测试条件' : '配置未就绪'));
            foreach ($report['blockers'] as $blocker) {
                $output->writeln('- ' . $blocker);
            }
            if ($report['transaction'] !== null) {
                $output->writeln('支付单：' . $report['transaction']['payment_no'] . '；本地账务：' . $report['transaction']['local_consistency']);
                foreach ($report['transaction']['checks'] as $check) {
                    $output->writeln('- ' . $check['status'] . '：' . $check['message']);
                }
            }
            $output->writeln('真实商户、手机付款、公网回调和商户账单仍待人工验收；本命令未调用上游接口。');
        }
        return $report['ready_for_external_test'] && ($report['transaction']['local_consistency'] ?? 'pass') === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
