<?php

declare(strict_types=1);

namespace app\modules\channel\application\sms;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\shared\plugin\dto\sms\SmsMessage;
use app\shared\plugin\dto\sms\SmsResult;

/** 内部短信门面，调用方持久化事件、限流并预留幂等记录后执行；不开放任意短信公网接口。 */
final readonly class SmsChannelService
{
    /**
     * @param ChannelExecutionConfiguration $configuration 配置边界
     * @param TransactionAdapterFactoryInterface $adapters 固定加载器
     */
    public function __construct(private ChannelExecutionConfiguration $configuration, private TransactionAdapterFactoryInterface $adapters)
    {
    }

    /**
     * @param int $channelId 已授权通道
     * @param int $revision 封存修订
     * @param SmsMessage $message 已授权短信
     * @return SmsResult 受理结果
     */
    public function send(int $channelId, int $revision, SmsMessage $message): SmsResult
    {
        [$id, $configuration] = $this->configuration->resolve('sms', $channelId, $revision, true);
        return $this->adapters->sms($id)->send($message, $configuration);
    }
}
