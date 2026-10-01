<?php

declare(strict_types=1);

namespace app\modules\order\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\order\contract\OrderRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 授权后查询订单与订单项，暂不提供人工创建或修改交易状态的入口。 */
final readonly class ViewAdminOrdersService
{
    public function __construct(
        private AdminAccessControlService $access,
        private OrderRepositoryInterface $orders,
    ) {
    }

    /**
     * 授权后分页查询订单。
     * @param AdminAccount $actor    当前管理员
     * @param string       $keyword  搜索关键词
     * @param string|null  $status   订单状态
     * @param int          $page     页码
     * @param int          $pageSize 每页数量
     * @return array{data: list<array<string, mixed>>, total: int} 订单分页
     * @throws BusinessException 无查看权限时抛出
     */
    public function paginate(AdminAccount $actor, string $keyword, ?string $status, int $page, int $pageSize): array
    {
        $this->access->requirePermission($actor, 'admin.page.order-list.view');

        return $this->orders->paginate($keyword, $status, $page, $pageSize);
    }

    /**
     * 授权后查询订单详情及订单项。
     * @param AdminAccount $actor 当前管理员
     * @param int          $id    订单标识
     * @return array<string, mixed> 订单详情
     * @throws BusinessException 无权限或订单不存在时抛出
     */
    public function detail(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.page.order-list.view');

        return $this->orders->findWithItems($id)
            ?? throw new BusinessException('订单不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
