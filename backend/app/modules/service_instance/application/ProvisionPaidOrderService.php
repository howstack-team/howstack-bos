<?php

declare(strict_types=1);
namespace app\modules\service_instance\application;

use app\exception\BusinessException;
use app\modules\order\contract\{OrderProvisioningInterface, OrderProvisioningRepositoryInterface};
use app\modules\provider\contract\ProviderProvisioningGatewayInterface;
use app\modules\service_instance\contract\ProvisioningRepositoryInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\provider\ProvisionResult;
use app\shared\plugin\exception\PluginException;

/** 逐实例交付：付款原子建任务、租约领取、事务外调用、结果持久化，未知只查单。 */
final readonly class ProvisionPaidOrderService implements OrderProvisioningInterface
{
    /** @param OrderProvisioningRepositoryInterface $orders 订单协作端口 @param ProvisioningRepositoryInterface $tasks 服务任务 @param ProviderProvisioningGatewayInterface $gateway 供应生产 @param TransactionManagerInterface $transactions 本地事务 */
    public function __construct(private OrderProvisioningRepositoryInterface $orders, private ProvisioningRepositoryInterface $tasks,
        private ProviderProvisioningGatewayInterface $gateway, private TransactionManagerInterface $transactions) {}
    /** @inheritDoc */
    public function prepare(int $orderId, #[\SensitiveParameter] ?string $password): void
    {
        if ($password !== null && (strlen($password) < 8 || strlen($password) > 72 || preg_match('/[\x00-\x20\x7f]/', $password))) {
            throw new BusinessException('实例密码须为8至72位且不包含空白字符', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['login_password' => ['实例密码须为8至72位且不包含空白字符']]]);
        }
        $this->tasks->prepare($orderId, $password ?? bin2hex(random_bytes(16)) . '@Aa1');
    }
    /** @inheritDoc */
    public function enqueue(int $orderId): void
    {
        $order = $this->orders->lock($orderId);
        if ($order['payment_status'] !== 'paid') throw new BusinessException('未付款订单不能开通', ApiErrorCode::CONFLICT, 409);
        $this->prepare($orderId, null);
        foreach ($order['units'] as $unit) {
            if ($unit['service_id'] !== null || $unit['refunded_minor'] > 0) continue;
            $snapshot = $unit['snapshot'];
            if (($snapshot['checkout_version'] ?? null) !== 1) continue;
            $months = $snapshot['configuration']['duration_months'] ?? null;
            if (!is_int($months) || $months < 1 || $months > 120) throw new BusinessException('订单缺少有效服务周期，请重新选配下单', ApiErrorCode::CONFLICT, 409);
            $capture = $this->gateway->capture($snapshot['delivery_binding'], $snapshot['provisioning']);
            $this->orders->link($unit['id'], $this->tasks->create($order, $unit, $capture));
        }
        if ($this->tasks->forOrder($orderId)) $this->orders->status($orderId, 'fulfilling');
    }
    /** @param int $limit 单次执行上限，不在 HTTP 付款请求内运行 @return int 实际领取数 */
    public function tick(int $limit = 20): int
    {
        $count = 0;
        foreach ($this->tasks->due(min(100, max(1, $limit))) as $due) {
            $task = $this->transactions->run(function () use ($due): ?array {
                $order = $this->orders->lock($due['order_id']);
                if (!in_array($order['payment_status'], ['paid', 'partial_refund'], true)) return null;
                return $this->tasks->claim($due['id']);
            });
            if ($task === null) continue;
            $count++;
            $error = null;
            try { $result = $this->gateway->execute($task, $task['operation'] === 'create' ? $this->tasks->password($task['order_id']) : ''); }
            catch (\Throwable $failure) {
                // 外部失败不可推断为未创建，保留原请求查单，不记录密码或供应原报文。
                $result = new ProvisionResult('unknown');
                $error = $failure instanceof PluginException && in_array($failure->reason, ['adapter_changed', 'provider_unavailable'], true)
                    ? $failure->reason : ($failure instanceof BusinessException && $failure->httpStatus() === 409 ? 'credential_unavailable' : 'provider_result_unknown');
            }
            $this->transactions->run(function () use ($task, $result, $error): void {
                $this->orders->lock($task['order_id']);
                if ($this->tasks->finish($task, $result, $error)) $this->refreshOrder($task['order_id']);
            });
        }
        $this->tasks->cleanup();
        return $count;
    }
    /** @param int $serviceId 本地服务 @return void 由已认证且有权限的管理用例调用 */
    public function retry(int $serviceId): void
    {
        $task = $this->tasks->forService($serviceId) ?? throw new BusinessException('开通任务不存在', ApiErrorCode::NOT_FOUND, 404);
        $this->transactions->run(function () use ($task): void {
            $order = $this->orders->lock($task['order_id']);
            $unit = null;
            foreach ($order['units'] as $candidate) if ($candidate['id'] === $task['order_unit_id']) $unit = $candidate;
            if (!$unit || $unit['refunded_minor'] > 0 || $unit['reserved_refund_minor'] > 0 || !in_array($order['payment_status'], ['paid', 'partial_refund'], true)) {
                throw new BusinessException('该实例存在退款或订单状态不允许重试', ApiErrorCode::CONFLICT, 409);
            }
            $this->tasks->password($task['order_id']);
            $capture = $task['connection_encrypted'] ? [] : $this->gateway->capture($task['context']['delivery_binding'], $task['context']['provisioning']);
            $this->tasks->retry($task['id'], $capture);
            $this->orders->status($order['id'], 'fulfilling');
        });
    }
    /** @param int $serviceId 人工已核实未创建的实例 @return void 确认失败，后续退款仍经过财务流程 */
    public function confirmNotCreated(int $serviceId, int $actorId, string $reason, string $reference): void
    {
        $task = $this->tasks->forService($serviceId) ?? throw new BusinessException('开通任务不存在', ApiErrorCode::NOT_FOUND, 404);
        $this->transactions->run(function () use ($task, $actorId, $reason, $reference): void {
            $this->orders->lock($task['order_id']);
            $this->tasks->confirmNotCreated($task['id'], $actorId, $reason, $reference);
            $this->refreshOrder($task['order_id']);
        });
    }
    /** @param int $orderId 已锁定订单 @return void 由逐台任务汇总交易状态 */
    private function refreshOrder(int $orderId): void
    {
        $states = array_column($this->tasks->forOrder($orderId), 'status');
        if (!$states) return;
        $status = count(array_filter($states, fn (string $s): bool => $s === 'succeeded')) === count($states) ? 'completed'
            : (array_diff($states, ['succeeded', 'failed']) === [] ? 'failed' : 'fulfilling');
        $this->orders->status($orderId, $status);
    }
}
