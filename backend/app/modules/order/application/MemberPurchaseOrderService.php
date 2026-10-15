<?php

declare(strict_types=1);

namespace app\modules\order\application;

use app\modules\order\contract\MemberOrderRepositoryInterface;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 会员只使用本人身份下单；报价重新由后端校验，不信任浏览器金额。 */
final readonly class MemberPurchaseOrderService
{
    public function __construct(private CreatePurchaseOrderService $creator, private MemberOrderRepositoryInterface $orders,
        private \app\modules\product\contract\ProductPurchaseInputInterface $inputs) {}

    /** @param int $memberId 认证会员 @param array<string,mixed> $input 验证请求 @return array{id:int} 待付款订单 */
    public function create(int $memberId, array $input): array
    {
        $input['selection'] = $this->inputs->clean('cloud_server', $input['selection']);
        return $this->creator->create('member:' . $memberId, $memberId, $input, publicOnly: true);
    }
    /** @param int $memberId 认证会员 @param int $page 页码 @param int $size 每页条数 @return array<string,mixed> 本人列表 */
    public function page(int $memberId, int $page, int $size): array { return $this->orders->paginate($memberId, $page, $size); }
    /** @param int $memberId 认证会员 @param int $id 订单 @return array<string,mixed> 本人详情 */
    public function detail(int $memberId, int $id): array
    {
        return $this->orders->find($memberId, $id) ?? throw new BusinessException('订单不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
