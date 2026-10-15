<?php

declare(strict_types=1);

namespace app\modules\channel\application\payment;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\shared\plugin\dto\payment\PaymentRequest;
use app\shared\plugin\dto\payment\PaymentResult;

/** 供订单及财务业务调用；只执行外部支付能力，不擅自完成入账或履约。 */
final readonly class PaymentChannelService
{
    /**
     * @param ChannelExecutionConfiguration $configuration 本次配置
     * @param TransactionAdapterFactoryInterface $adapters 固定接口加载器
     */
    public function __construct(private ChannelExecutionConfiguration $configuration, private TransactionAdapterFactoryInterface $adapters)
    {
    }

    /**
     * @param int $channelId 已封存通道
     * @param int $revision 通道修订
     * @param PaymentRequest $request 付款快照
     * @return PaymentResult 付款入口
     */
    public function create(int $channelId, int $revision, PaymentRequest $request): PaymentResult
    {
        [$id, $configuration] = $this->configuration->resolve('payment', $channelId, $revision, true);
        return $this->adapters->payment($id)->create($request, $configuration);
    }

    /**
     * @param int $channelId 原通道
     * @param int $revision 原修订
     * @param PaymentRequest $request 原付款快照
     * @return PaymentResult 查单结果
     */
    public function query(int $channelId, int $revision, PaymentRequest $request): PaymentResult
    {
        [$id, $configuration] = $this->configuration->resolve('payment', $channelId, $revision, false);
        return $this->adapters->payment($id)->query($request, $configuration);
    }

    /**
     * @param int $channelId 原通道
     * @param int $revision 原修订
     * @param PaymentRequest $request 原付款快照
     * @return PaymentResult 关闭结果
     */
    public function close(int $channelId, int $revision, PaymentRequest $request): PaymentResult
    {
        [$id, $configuration] = $this->configuration->resolve('payment', $channelId, $revision, false);
        return $this->adapters->payment($id)->close($request, $configuration);
    }

    /**
     * @param int $channelId 原通道
     * @param int $revision 原修订
     * @param array<string, string> $parameters 原始通知字段
     * @param PaymentRequest $request 宿主原快照
     * @return PaymentResult 验证结果，宿主提交事务后才回复 success
     */
    public function verifyNotification(int $channelId, int $revision, array $parameters, PaymentRequest $request): PaymentResult
    {
        [$id, $configuration] = $this->configuration->resolve('payment', $channelId, $revision, false);
        return $this->adapters->payment($id)->verifyNotification($parameters, $request, $configuration);
    }

    /**
     * @param int $channelId 原通道
     * @param int $revision 原修订
     * @param \app\shared\plugin\dto\payment\PaymentNotification $notification 未重编码的通知
     * @param PaymentRequest $request 持久化快照
     * @return PaymentResult 验证结果，提交幂等入账事务后才能应答
     */
    public function verifyJsonNotification(int $channelId, int $revision, \app\shared\plugin\dto\payment\PaymentNotification $notification, PaymentRequest $request): PaymentResult
    {
        [$id, $configuration] = $this->configuration->resolve('payment', $channelId, $revision, false);
        $adapter = $this->adapters->payment($id);
        if (!$adapter instanceof \app\shared\plugin\contract\JsonPaymentNotificationAdapterInterface) {
            throw new \app\shared\plugin\exception\PluginException('通道不支持 JSON 通知', 'invalid_notification');
        }
        return $adapter->verifyJsonNotification($notification, $request, $configuration);
    }
}
