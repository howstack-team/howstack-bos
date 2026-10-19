<?php

declare(strict_types=1);

namespace app\modules\service_instance\contract;

use app\shared\plugin\dto\provider\LifecycleResult;

/** 应用层统一事务；锁顺序订单（如有）→服务→任务，供应调用不持有锁。 */
interface ServiceLifecycleRepositoryInterface
{
    /**
     * @param int $id 服务
     * @param bool $lock 是否加行锁
     * @return array<string,mixed> 服务及首次供应来源
     */
    public function service(int $id, bool $lock = false): array;
    /**
     * @param int $serviceId 服务
     * @return list<array<string,mixed>> 尚未结束的续费任务
     */
    public function renewals(int $serviceId): array;
    /**
     * @param int $serviceId 服务
     * @param int $orderId 订单
     * @param int $months 月数
     * @param string $expiry 原到期时间
     * @return void 建立待付记录
     */
    public function reserve(int $serviceId, int $orderId, int $months, string $expiry): void;
    /**
     * @param int $orderId 已锁定已付订单
     * @return void 固定续费目标并入队
     */
    public function paid(int $orderId): void;
    /**
     * @param int $limit 上限
     * @return list<int> 需到期处理的服务
     */
    public function expired(int $limit): array;
    /**
     * @param int $serviceId 服务
     * @return bool 本次是否首次标记到期并登记后续处理
     */
    public function expire(int $serviceId): bool;
    /**
     * @param int $limit 每档上限
     * @return list<array{service_id:int,days:int}> 未发送的七、三、一天提醒
     */
    public function reminders(int $limit): array;
    /**
     * @param int $id 服务
     * @param int $days 提醒档位
     * @return array<string,mixed>|null 原子登记后的服务，重复或已过期返回null
     */
    public function recordReminder(int $id, int $days): ?array;
    /**
     * @param int $limit 上限
     * @return list<array<string,mixed>> 待领取任务
     */
    public function due(int $limit): array;
    /**
     * @param int $id 任务
     * @return array<string,mixed>|null 带租约及供应来源的执行上下文
     */
    public function claim(int $id): ?array;
    /**
     * @param array<string,mixed> $task 当前租约
     * @return bool 记录外呼意图是否成功
     */
    public function submitted(array $task): bool;
    /**
     * @param array<string,mixed> $task 原领取快照
     * @param LifecycleResult $result 供应结果
     * @param string|null $error 脱敏分类
     * @return bool 是否已完成本次动作
     */
    public function finish(array $task, LifecycleResult $result, ?string $error): bool;
    /**
     * @param int $serviceId 服务
     * @return void 人工恢复查单，绝不重发未知操作
     */
    public function retry(int $serviceId): void;
}
