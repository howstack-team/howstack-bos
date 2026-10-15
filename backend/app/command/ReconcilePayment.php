<?php

declare(strict_types=1);

namespace app\command;

use app\modules\finance\application\OnlineRechargeService;
use support\Container;
use Symfony\Component\Console\{Command\Command, Input\InputInterface, Input\InputArgument, Output\OutputInterface};

#[\Symfony\Component\Console\Attribute\AsCommand('payment:reconcile', '核查原在线充值交易')]
final class ReconcilePayment extends Command
{
    protected function configure(): void {
        $this->setName('payment:reconcile')->setDescription('核查原在线充值交易，不创建新付款或人工标记到账')
            ->addArgument('payment-id', InputArgument::REQUIRED, '后台支付记录的内部ID');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $id = filter_var($input->getArgument('payment-id'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) { $output->writeln('<error>请填写正确的支付记录ID</error>'); return Command::INVALID; }
        $result = Container::get(OnlineRechargeService::class)->inspect($id);
        $output->writeln('充值单号：' . $result['recharge_no'] . '；状态：' . $result['status']);
        return Command::SUCCESS;
    }
}
