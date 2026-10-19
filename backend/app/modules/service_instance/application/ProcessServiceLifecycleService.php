<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\exception\BusinessException;
use app\modules\order\contract\OrderProvisioningRepositoryInterface;
use app\modules\provider\contract\ProviderLifecycleGatewayInterface;
use app\modules\service_instance\contract\ServiceLifecycleRepositoryInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\provider\LifecycleResult;
use app\shared\plugin\exception\PluginException;

/** 付款事务只登记任务；定时进程在事务外执行供应，未知结果只查单。 */
final readonly class ProcessServiceLifecycleService
{
    /**
     * @param ServiceLifecycleRepositoryInterface $tasks 任务及服务
     * @param OrderProvisioningRepositoryInterface $orders 订单协作
     * @param ProviderLifecycleGatewayInterface $gateway 供应执行
     * @param TransactionManagerInterface $transactions 本地事务
     * @param \app\modules\message\contract\MessagePublisherInterface $messages 事务内消息登记
     */
    public function __construct(
        private ServiceLifecycleRepositoryInterface $tasks,
        private OrderProvisioningRepositoryInterface $orders,
        private ProviderLifecycleGatewayInterface $gateway,
        private TransactionManagerInterface $transactions,
        private \app\modules\message\contract\MessagePublisherInterface $messages,
    ) {
    }

    /**
     * @param int $orderId 已锁定的续费订单
     * @return void 与钱包扣款同一事务
     */
    public function paid(int $orderId): void
    {
        $order = $this->orders->lock($orderId);
        if ($order['order_type'] !== 'renewal' || $order['payment_status'] !== 'paid' || count($order['units']) !== 1) {
            throw new BusinessException('续费订单状态异常', ApiErrorCode::CONFLICT, 409);
        }
        $serviceId = $order['units'][0]['service_id'];
        if (!is_int($serviceId) || $serviceId < 1) {
            throw new BusinessException('续费订单没有关联原实例', ApiErrorCode::CONFLICT, 409);
        }
        $service = $this->tasks->service($serviceId, true);
        if ($service['member_id'] !== $order['member_id'] || !$service['source'] || $service['source']['status'] !== 'succeeded') {
            throw new BusinessException('实例归属或开通状态已变化，付款未扣款', ApiErrorCode::CONFLICT, 409);
        }
        try {
            $this->gateway->assertSupported($service['source']);
        } catch (PluginException $error) {
            throw new BusinessException('原供应插件不支持当前续费，付款未扣款，请联系管理员', ApiErrorCode::CONFLICT, 409, previous: $error);
        }
        $this->tasks->paid($orderId);
        $this->orders->status($orderId, 'fulfilling');
    }

    /**
     * @param int $limit 每批上限
     * @return int 已领取的供应任务数
     */
    public function tick(int $limit = 10): int
    {
        foreach ($this->tasks->reminders($limit) as $candidate) {
            $this->transactions->run(function () use ($candidate): void {
                $service = $this->tasks->recordReminder($candidate['service_id'], $candidate['days']);
                if ($service !== null) {
                    $this->notify($service, 'service_due', 'due' . $candidate['days']);
                }
            });
        }
        foreach ($this->tasks->expired($limit) as $id) {
            $this->transactions->run(function () use ($id): void {
                if ($this->tasks->expire($id)) {
                    $this->notify($this->tasks->service($id), 'service_expired', 'expired');
                }
            });
        }
        $processed = 0;
        foreach ($this->tasks->due($limit) as $due) {
            $task = $this->transactions->run(function () use ($due): ?array {
                if ($due['order_id'] !== null) {
                    $order = $this->orders->lock($due['order_id']);
                    if ($order['payment_status'] !== 'paid') {
                        return null;
                    }
                }
                return $this->tasks->claim($due['id']);
            });
            if ($task === null) {
                continue;
            }
            $processed++;
            $error = null;
            try {
                $this->gateway->assertSupported($task['source'], $task['action']);
                $intent = $this->transactions->run(fn (): bool => $this->tasks->submitted($task));
                if (!$intent) {
                    continue;
                }
                $result = $this->gateway->execute($task);
            } catch (\Throwable $failure) {
                $result = new LifecycleResult('unknown');
                $error = $failure instanceof PluginException && in_array($failure->reason, ['adapter_changed', 'lifecycle_unsupported', 'operation_unsupported', 'credential_unavailable'], true)
                    ? $failure->reason : 'provider_result_unknown';
            }
            $this->transactions->run(function () use ($task, $result, $error): void {
                if ($task['order_id'] !== null) {
                    $this->orders->lock($task['order_id']);
                }
                if ($this->tasks->finish($task, $result, $error)) {
                    if ($task['order_id'] !== null) {
                        $this->orders->status($task['order_id'], 'completed');
                        $this->notify($this->tasks->service($task['service_id']), 'renewal_succeeded', 'renewed');
                    } elseif ($task['action'] === 'suspend') {
                        $this->notify($this->tasks->service($task['service_id']), 'service_suspended', 'suspended');
                    }
                }
            });
        }
        return $processed;
    }

    /**
     * @param array<string,mixed> $service 原服务
     * @param string $scene 固定场景
     * @param string $phase 去重阶段
     * @return void 与服务状态同事务登记消息
     */
    private function notify(array $service, string $scene, string $phase): void
    {
        $key = 'service.' . $service['id'] . '.' . $phase . '.' . hash('sha256', $service['expires_at']);
        $this->messages->publish($key, $scene, 'service', $service['id'], $service['member_id'], [
            'site_name' => '云业务运营系统', 'service_name' => $service['display_name'], 'service_no' => $service['instance_no'],
            'due_at' => $service['expires_at'], 'reason' => '服务到期',
        ], ['customer']);
    }

    /**
     * @param int $id 服务
     * @return void 经管理权限核验后重新排队
     */
    public function retry(int $id): void
    {
        $this->transactions->run(fn () => $this->tasks->retry($id));
    }
}
