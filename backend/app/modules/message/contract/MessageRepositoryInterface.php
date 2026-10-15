<?php

declare(strict_types=1);

namespace app\modules\message\contract;

interface MessageRepositoryInterface
{
    /** @param list<array<string, mixed>> $targets 带加密前模板快照的投递计划；必须在事务中记录 */
    public function record(string $eventKey, string $scene, string $businessType, int $businessId, array $targets): int;
    /** @return array<string, mixed>|null 原子领取；重复消费返回null */
    public function claim(int $id): ?array;
    public function finish(int $id, string $lease, string $status, string $error, int $duration, bool $retry): void;
    /** @return list<int> 待投递任务，含Redis未收到时的恢复补投 */
    public function due(int $limit): array;
    public function queued(int $id): void;
    public function recoverExpired(): void;
    /** @return array<string, mixed> 当前账户持久化收件箱 */
    public function inbox(string $audience, int $accountId, array $query): array;
    /** @return array<string, mixed> 当前账户全部未读分类计数 */
    public function summary(string $audience, int $accountId): array;
    public function read(string $audience, int $accountId, ?int $recipientId): void;
    /** @return array<string, mixed> 脱敏逻辑消息列表 */
    public function logs(array $query): array;
    /** @return array<string, mixed> 脱敏任务及尝试明细 */
    public function detail(int $id): array;
    public function retry(int $id): void;
}
