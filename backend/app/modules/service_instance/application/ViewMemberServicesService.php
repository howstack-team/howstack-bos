<?php

declare(strict_types=1);
namespace app\modules\service_instance\application;
use app\exception\BusinessException;
use app\modules\service_instance\contract\{MemberServiceRepositoryInterface, ProvisioningRepositoryInterface};
use app\shared\http\ApiErrorCode;
/** 服务展示与初始密码读取均核验本人归属；密码不进入普通详情响应。 */
final readonly class ViewMemberServicesService
{
    /** @param MemberServiceRepositoryInterface $services 本人服务 @param ProvisioningRepositoryInterface $tasks 独立初始凭据 */
    public function __construct(private MemberServiceRepositoryInterface $services, private ProvisioningRepositoryInterface $tasks) {}
    /** @param int $memberId 本人 @param int $page 页码 @param int $size 每页条数 @return array<string,mixed> 本人列表 */
    public function page(int $memberId, int $page, int $size): array { return $this->services->page($memberId, $page, $size); }
    /** @param int $memberId 本人 @param int $id 服务 @return array<string,mixed> 本人详情 */
    public function detail(int $memberId, int $id): array
    {
        return $this->services->find($memberId, $id) ?? throw new BusinessException('服务不存在', ApiErrorCode::NOT_FOUND, 404);
    }
    /** @param int $memberId 本人 @param int $id 服务 @return array{password:string} 限时初始密码，调用方须禁止缓存 */
    public function password(int $memberId, int $id): array
    {
        $service = $this->detail($memberId, $id);
        if (!($service['initial_password_available'] ?? false)) throw new BusinessException('原初始密码已失效或查看期限已过', ApiErrorCode::CONFLICT, 409);
        if ($service['status'] !== 'active') throw new BusinessException('实例尚未开通', ApiErrorCode::CONFLICT, 409);
        if (!$service['activated_at'] || strtotime($service['activated_at']) < time() - 172800) throw new BusinessException('初始密码查看期限已过', ApiErrorCode::CONFLICT, 409);
        $task = $this->tasks->forService($id) ?? throw new BusinessException('该实例不提供初始密码', ApiErrorCode::CONFLICT, 409);
        return ['password' => $this->tasks->password($task['order_id'])];
    }
}
