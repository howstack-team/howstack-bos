<?php

declare(strict_types=1);

namespace app\modules\compute\contract;

/** 共享库存原子端口；付款和释放必须在调用方业务事务中协作。 */
interface ComputeInventoryRepositoryInterface
{
    /** @return list<array<string, mixed>> 公开库存配置 */
    public function all(): array;
    /** @param int $zoneId 部署位置 @param int $flavorId 规格 @param int|null $capacity 总量 @param int $revision 乐观版本 @return array<string, mixed> 保存结果 */
    public function configure(int $zoneId, int $flavorId, ?int $capacity, int $revision): array;
    /** @param int $zoneId 部署位置 @param int $flavorId 规格 @param string $key 幂等键 @param int $quantity 数量 @param \DateTimeImmutable $expires 有效期 @return array<string, mixed> 预占 */
    public function reserve(int $zoneId, int $flavorId, string $key, int $quantity, \DateTimeImmutable $expires): array;
    /** @param string $key 已存在预占 @param string $target occupied或released @param bool $resourceReleased 正式占用释放时的供应确认 @return void */
    public function transition(string $key, string $target, bool $resourceReleased = false): void;
    /** @param \DateTimeImmutable $now 当前时间 @return int 到期预占释放条数 */
    public function releaseExpired(\DateTimeImmutable $now): int;
}
