<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\exception\BusinessException;
use app\modules\order\contract\RenewalOrderRepositoryInterface;
use app\modules\product\contract\ProductRenewalPricingInterface;
use app\modules\provider\contract\ProviderLifecycleGatewayInterface;
use app\modules\service_instance\contract\ServiceLifecycleRepositoryInterface;
use app\shared\contract\{TransactionManagerInterface, OutboxWriterInterface};
use app\shared\http\ApiErrorCode;
use app\shared\plugin\exception\PluginException;

/** 续费不增加实例或库存；新交易与原服务关联，期间在付款时固定。 */
final readonly class RenewServiceService
{
    /**
     * @param ServiceLifecycleRepositoryInterface $services 生命周期仓储
     * @param RenewalOrderRepositoryInterface $orders 订单域门面
     * @param ProductRenewalPricingInterface $pricing 类型计价
     * @param ProviderLifecycleGatewayInterface $gateway 原供应能力
     * @param TransactionManagerInterface $transactions 事务
     * @param OutboxWriterInterface $outbox 事务事件
     */
    public function __construct(
        private ServiceLifecycleRepositoryInterface $services,
        private RenewalOrderRepositoryInterface $orders,
        private ProductRenewalPricingInterface $pricing,
        private ProviderLifecycleGatewayInterface $gateway,
        private TransactionManagerInterface $transactions,
        private OutboxWriterInterface $outbox,
    ) {
    }

    /**
     * @param int|null $memberId 已认证本人，后台由独立RBAC用例传null
     * @param int $id 实例
     * @param int $months 月份数，0仅取可选周期
     * @return array<string,mixed> 公开报价
     */
    public function quote(?int $memberId, int $id, int $months): array
    {
        $service = $this->eligible($memberId, $id);
        $result = $this->pricing->quote($service['product_type'], $service['source']['context'], $months);
        $data = ['service_id' => $id, 'expires_at' => $service['expires_at'], 'durations' => array_map(static fn (array $period): array => array_intersect_key($period, array_flip(['months', 'label'])), $result['durations'])];
        if ($months > 0) {
            $data += ['months' => $months, 'amount_minor' => $result['price']['amount_minor'], 'currency' => $result['price']['currency']];
        }
        return $data;
    }

    /**
     * @param int|null $memberId 本人或已核权后台
     * @param int $id 实例
     * @param array{months:int,expected_amount_minor:int,request_key:string} $input 白名单输入
     * @param string $scope 认证身份产生的幂等域
     * @return array{id:int} 待付款订单
     */
    public function create(?int $memberId, int $id, array $input, string $scope): array
    {
        return $this->transactions->run(function () use ($memberId, $id, $input, $scope): array {
            $key = $scope . ':renewal:' . $input['request_key'];
            $hash = hash('sha256', json_encode([$memberId, $id, $input['months'], $input['expected_amount_minor']], JSON_THROW_ON_ERROR));
            $existing = $this->orders->begin($key, $hash);
            if ($existing !== null) {
                return ['id' => $existing];
            }
            $service = $this->eligible($memberId, $id, true);
            foreach ($this->services->renewals($id) as $task) {
                if (!in_array($this->orders->state($task['order_id']), ['cancelled', 'refunded', 'missing'], true)) {
                    self::conflict('该实例已有未结束的续费订单，请先付款或取消原订单');
                }
            }
            $quote = $this->pricing->quote($service['product_type'], $service['source']['context'], $input['months']);
            if ($quote['price']['amount_minor'] !== $input['expected_amount_minor']) {
                self::conflict('续费费用已变化，请重新计算后确认');
            }
            $quote += ['months' => $input['months'], 'expected_expires_at' => $service['expires_at']];
            $orderId = $this->orders->create($service['member_id'], $id, $quote);
            $this->services->reserve($id, $orderId, $input['months'], $service['expires_at']);
            $this->orders->finish($key, $orderId);
            $this->outbox->append('order.created.' . $orderId, 'order.created', $orderId, ['order_id' => $orderId]);
            return ['id' => $orderId];
        });
    }

    /**
     * @param int|null $memberId 已认证身份
     * @param int $id 实例
     * @param bool $lock 事务内串行化
     * @return array<string,mixed> 内部续费来源
     */
    private function eligible(?int $memberId, int $id, bool $lock = false): array
    {
        $service = $this->services->service($id, $lock);
        if ($memberId !== null && $service['member_id'] !== $memberId) {
            throw new BusinessException('服务不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        if (!in_array($service['status'], ['active', 'expired'], true) || !$service['expires_at']
            || !$service['source'] || $service['source']['status'] !== 'succeeded' || !$service['source']['external_id']) {
            self::conflict('该实例当前不能续费，请联系管理员');
        }
        try {
            $this->gateway->assertSupported($service['source']);
        } catch (PluginException $error) {
            throw new BusinessException('原供应插件尚不可用于续费，请联系管理员核查', ApiErrorCode::CONFLICT, 409, previous: $error);
        }
        return $service;
    }

    /**
     * @param string $message 可公开原因
     * @return never
     */
    private static function conflict(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
