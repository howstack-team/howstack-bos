<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\payment\PaymentNotification;
use app\shared\plugin\dto\payment\PaymentRequest;
use app\shared\plugin\dto\payment\PaymentResult;

/** 同一支付 v1 契约下的 JSON 原文通知能力；表单通知保持原接口。 */
interface JsonPaymentNotificationAdapterInterface extends PaymentAdapterInterface
{
    /**
     * @param PaymentNotification $notification 原始材料
     * @param PaymentRequest $request 宿主持久化快照
     * @param Configuration $configuration 原商户配置
     * @return PaymentResult 验签、解密、核对身份与金额后的安全结果
     */
    public function verifyJsonNotification(PaymentNotification $notification, PaymentRequest $request, Configuration $configuration): PaymentResult;
}
