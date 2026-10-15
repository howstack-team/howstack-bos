<?php

declare(strict_types=1);

namespace app\modules\order\application;

use app\modules\order\contract\OrderManagementRepositoryInterface;
use app\modules\product\contract\{OrderSalesCatalogInterface, ProductTypeRegistryInterface, ProductPurchaseInputInterface, ProductPurchaseAvailabilityInterface};
use app\shared\contract\{TransactionManagerInterface, OutboxWriterInterface};
use app\modules\message\contract\MessagePublisherInterface;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 后台与会员共用订单创建事务、计价、幂等、实例分摊和通知。入口各自授权。 */
final readonly class CreatePurchaseOrderService
{
    public function __construct(private OrderManagementRepositoryInterface $orders, private OrderSalesCatalogInterface $catalog,
        private ProductTypeRegistryInterface $types, private TransactionManagerInterface $transactions,
        private OutboxWriterInterface $outbox, private MessagePublisherInterface $messages,
        private ProductPurchaseInputInterface $inputs, private ProductPurchaseAvailabilityInterface $availability) {}

    /** @param string $scope 调用入口与操作者的幂等作用域 @param int $memberId 已由入口认证确定的会员 @param array<string, mixed> $input 购买请求 @param bool $publicOnly 是否仅允许公开产品 @return array{id:int} 新建或既有订单 */
    public function create(string $scope, int $memberId, array $input, bool $publicOnly = false): array
    {
        return $this->transactions->run(function () use ($scope, $memberId, $input, $publicOnly): array {
            $key = $scope . ':' . $input['request_key'];
            $values = $input + ['member_id' => $memberId]; unset($values['request_key']);
            $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            $existing = $this->orders->begin($key, $hash);
            if ($existing !== null) return ['id' => $existing];
            $member = $this->orders->member($memberId);
            if (!$member || $member['status'] !== 1) $this->fail('会员不存在或已禁用');
            $sale = $this->catalog->published($input['offer_id'], $input['version_id'], true);
            if ($publicOnly && ($sale['product_type'] !== 'cloud_server' || ($sale['snapshot']['schema_version'] ?? '') !== '2.0.0')) $this->fail('该产品尚未开放会员购买');
            $handler = $this->types->get($sale['product_type']);
            if (!$handler->supportsVersion($sale['type_version'])) $this->fail('该销售版本不支持下单');
            $price = $handler->price($sale['snapshot'], $this->inputs->clean($sale['product_type'], $input['selection']));
            if ($price['currency'] !== 'CNY' || $price['amount_minor'] <= 0 || $price['amount_minor'] > 100000000) $this->fail('当前仅支持人民币正价订单，单笔上限为100万元');
            if ($price['amount_minor'] !== $input['expected_amount_minor']) $this->fail('费用已变化，请重新计算费用后确认');
            $this->availability->check($sale['product_type'], $price['configuration']);
            $id = $this->orders->create($member, $sale, $price, $handler->provisioningConfiguration($sale['snapshot'], $price['configuration']), $input['remark'] ?? '');
            $this->orders->finish($key, $id);
            $this->outbox->append('order.created.' . $id, 'order.created', $id, ['order_id' => $id]);
            $this->messages->publish('order.created.' . $id, 'order_created', 'order', $id, $memberId,
                ['site_name' => '云业务运营系统', 'order_no' => $this->orders->lock($id)['order_no'],
                    'amount' => intdiv($price['amount_minor'], 100) . '.' . str_pad((string) ($price['amount_minor'] % 100), 2, '0', STR_PAD_LEFT)]);
            return ['id' => $id];
        });
    }

    /** @param string $message 可公开的冲突原因 @return never */
    private function fail(string $message): never { throw new BusinessException($message, ApiErrorCode::CONFLICT, 409); }
}
