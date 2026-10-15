<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\payment\PaymentRequest;
use app\shared\plugin\dto\payment\PaymentResult;

/** 支付 v1：插件执行上游协议，宿主负责付款快照、回调路由、入账及履约。 */
interface PaymentAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * @param PaymentRequest $request 宿主付款快照
     * @param Configuration $configuration 本次配置
     * @return PaymentResult 生成付款入口，不代表已支付
     */
    public function create(PaymentRequest $request, Configuration $configuration): PaymentResult;

    /**
     * @param PaymentRequest $request 原付款快照
     * @param Configuration $configuration 本次配置
     * @return PaymentResult 主动查单结果
     */
    public function query(PaymentRequest $request, Configuration $configuration): PaymentResult;

    /**
     * @param PaymentRequest $request 原付款快照
     * @param Configuration $configuration 本次配置
     * @return PaymentResult 关闭结果，未知时必须查单
     */
    public function close(PaymentRequest $request, Configuration $configuration): PaymentResult;

    /**
     * @param array<string, string> $parameters 未修改的原始表单通知
     * @param PaymentRequest $request 宿主持久化的原付款快照
     * @param Configuration $configuration 原商户配置
     * @return PaymentResult 验签且核对身份、订单及金额后的结果
     */
    public function verifyNotification(array $parameters, PaymentRequest $request, Configuration $configuration): PaymentResult;
}
