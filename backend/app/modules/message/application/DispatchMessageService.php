<?php

declare(strict_types=1);

namespace app\modules\message\application;

use app\modules\message\contract\MessageRepositoryInterface;
use app\modules\message\contract\MessageTransportInterface;
use app\modules\message\contract\MessageQueueInterface;
use Throwable;

/** 数据库原子领取阻止重复执行；发送结果未知不自动重发。 */
final readonly class DispatchMessageService
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private MessageTransportInterface $transport,
        private MessageQueueInterface $queue,
        private \app\modules\message\contract\MessageRecipientDirectoryInterface $directory,
    ) {
    }

    public function consume(int $id): void
    {
        $task = $this->messages->claim($id);
        if ($task === null) {
            return;
        }
        $started = hrtime(true);
        try {
            $result = !$this->directory->active($task['audience'], $task['account_id'])
                ? ['status' => 'failed', 'error' => 'recipient_disabled', 'retry' => false]
                : ($task['channel'] === 'message'
                ? ['status' => 'delivered', 'error' => '', 'retry' => false]
                : $this->transport->send($task['channel'], $task['request_key'], $task['payload']));
        } catch (Throwable) {
            // 执行开始后异常不能证明厂商未接受；禁止记录包含地址、凭据或正文的异常信息。
            $result = ['status' => 'unknown', 'error' => 'transport_outcome_unknown', 'retry' => false];
        }
        $this->messages->finish($id, $task['lease_key'], $result['status'], $result['error'],
            min(2147483647, (int) ((hrtime(true) - $started) / 1000000)), $result['retry']);
    }

    /** 消息消费者故障和Redis断开不会删除待发送数据库记录。 */
    public function tick(): void
    {
        $this->messages->recoverExpired();
        foreach ($this->messages->due(100) as $id) {
            try {
                if (!$this->queue->publish($id)) {
                    break;
                }
                $this->messages->queued($id);
            } catch (Throwable) {
                break;
            }
        }
    }
}
