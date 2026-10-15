<?php

declare(strict_types=1);

namespace app\modules\order\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\order\contract\OrderManagementRepositoryInterface;
use app\modules\product\contract\{OrderSalesCatalogInterface, ProductTypeRegistryInterface};
use app\shared\contract\TransactionManagerInterface;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

final readonly class ManageAdminOrdersService
{
    public function __construct(private AdminAccessControlService $access, private OrderManagementRepositoryInterface $orders,
        private OrderSalesCatalogInterface $catalog, private ProductTypeRegistryInterface $types,
        private TransactionManagerInterface $transactions, private PayOrderByWalletService $payments,
        private CreatePurchaseOrderService $creator, private \app\modules\product\contract\ProductPurchaseInputInterface $inputs) {}

    private function authorize(AdminAccount $actor, string $action): void
    {
        $this->access->requirePermission($actor, 'admin.page.order-list.view');
        $this->access->requirePermission($actor, 'admin.page.order-list.' . $action);
    }
    public function members(AdminAccount $actor, string $search): array
    {
        $this->authorize($actor, 'create');
        return $this->orders->members($search);
    }
    public function choices(AdminAccount $actor): array
    {
        $this->authorize($actor, 'create');
        return $this->catalog->choices();
    }
    public function preview(AdminAccount $actor, int $offerId, int $versionId, array $selection, bool $price): array
    {
        $this->authorize($actor, 'create');
        $sale = $this->catalog->published($offerId, $versionId);
        $handler = $this->types->get($sale['product_type']);
        if (!$handler->supportsVersion($sale['type_version'])) $this->fail('该发布版本不支持下单');
        $selection = $this->inputs->clean($sale['product_type'], $selection);
        return $price ? $handler->price($sale['snapshot'], $selection) : $handler->resolve($sale['snapshot'], $selection);
    }
    public function create(AdminAccount $actor, array $input): array
    {
        $this->authorize($actor, 'create');
        $memberId = $input['member_id'];
        unset($input['member_id']);
        return $this->creator->create((string) $actor->id, $memberId, $input);
    }
    public function act(AdminAccount $actor, int $id, string $action, array $input): array
    {
        $this->authorize($actor, $action);
        return $this->transactions->run(function () use ($id, $action, $input): array {
            $order = $this->orders->lock($id);
            if ($action === 'update') {
                $this->orders->remark($id, $input['remark'], $input['expected_remark']);
            } elseif ($action === 'cancel') {
                if ($order['status'] === 'cancelled' && $order['payment_status'] === 'unpaid' && $order['paid_minor'] === 0) return ['id' => $id];
                if ($order['status'] !== 'pending_payment' || $order['payment_status'] !== 'unpaid' || $order['paid_minor'] !== 0) $this->fail('仅未支付订单允许取消；已支付订单请办理退款');
                $this->orders->cancel($id);
            } elseif ($action === 'pay') {
                $this->payments->pay($order['member_id'], $id);
            } else $this->fail('不支持此订单操作');
            return ['id' => $id];
        });
    }
    private function fail(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
