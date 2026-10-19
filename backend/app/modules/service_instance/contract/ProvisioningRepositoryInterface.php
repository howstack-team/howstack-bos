<?php

declare(strict_types=1);

namespace app\modules\service_instance\contract;

use app\shared\plugin\dto\provider\ProvisionResult;

/** 服务域拥有任务、服务状态与独立凭据；方法由应用层控制事务和锁序。 */
interface ProvisioningRepositoryInterface
{
    /** @param int $orderId 订单 @param string $password 初始密码 @return void 仅首次加密保存 */
    public function prepare(int $orderId, #[\SensitiveParameter] string $password): void;
    /** @param int $orderId 订单 @return string 临时解密的初始密码，过期拒绝 */
    public function password(int $orderId): string;
    /** @param array<string,mixed> $order 订单 @param array<string,mixed> $unit 单元 @param array<string,mixed> $capture 连接固定资料 @return int 首次服务主键 */
    public function create(array $order, array $unit, array $capture): int;
    /** @param int $orderId 订单 @return list<array<string,mixed>> 该订单的任务 */
    public function forOrder(int $orderId): array;
    /** @param int $limit 本批上限 @return list<array<string,mixed>> 到期任务 */
    public function due(int $limit): array;
    /** @param int $id 任务 @return array<string,mixed>|null 带租约的任务，只有首次领取允许 create */
    public function claim(int $id): ?array;
    /** @param array<string,mixed> $task 领取任务 @param ProvisionResult $result 标准结果 @param string|null $error 稳定错误码 @return bool 本租约是否成功提交 */
    public function finish(array $task, ProvisionResult $result, ?string $error = null): bool;
    /** @param int $serviceId 服务 @return array<string,mixed>|null 内部任务，不直接返回客户端 */
    public function forService(int $serviceId): ?array;
    /** @param int $id 任务 @param array<string,mixed> $capture 尚未提交时可补齐连接 @return void 重新查询或重新执行已确认未创建的请求 */
    public function retry(int $id, array $capture): void;
    /** @param int $id 任务 @return void 人工核实供应商未创建资源后确认失败，保留请求审计 */
    public function confirmNotCreated(int $id, int $actorId, string $reason, string $reference): void;
    /** @return void 清理过期或已取消订单的独立密码 */
    public function cleanup(): void;
}
