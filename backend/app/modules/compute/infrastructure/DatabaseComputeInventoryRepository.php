<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure;

use app\exception\BusinessException;
use app\modules\compute\contract\ComputeInventoryRepositoryInterface;
use app\shared\http\ApiErrorCode;
use support\Db;

/** 锁顺序固定为库存池、预占记录；计数变化和幂等记录共同提交。 */
final class DatabaseComputeInventoryRepository implements ComputeInventoryRepositoryInterface
{
    /** @inheritDoc */
    public function all(): array
    {
        return Db::table('compute_inventory_pools')->orderBy('zone_id')->orderBy('flavor_id')->get()->map(fn (object $row): array => $this->map($row))->all();
    }

    /** @inheritDoc */
    public function configure(int $zoneId, int $flavorId, ?int $capacity, int $revision): array
    {
        if ($capacity !== null && ($capacity < 0 || $capacity > 100000000)) $this->fail('库存须为非负整数或不限制');
        return Db::transaction(function () use ($zoneId, $flavorId, $capacity, $revision): array {
            // insertOrIgnore由部署唯一索引协调首次配置，并在后续读锁检查revision。
            Db::table('compute_inventory_pools')->insertOrIgnore(['zone_id' => $zoneId, 'flavor_id' => $flavorId, 'capacity' => null, 'reserved' => 0, 'occupied' => 0, 'revision' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            $pool = $this->lockPool($zoneId, $flavorId);
            if ((int) $pool->revision !== $revision) $this->fail('库存配置已变化，请刷新后重试');
            if ($capacity !== null && $capacity < $pool->reserved + $pool->occupied) $this->fail('总库存不能小于已占用与预占之和');
            Db::table('compute_inventory_pools')->where('id', $pool->id)->update(['capacity' => $capacity, 'revision' => $revision + 1, 'updated_at' => date('Y-m-d H:i:s')]);
            return $this->map(Db::table('compute_inventory_pools')->where('id', $pool->id)->first());
        });
    }

    /** @inheritDoc */
    public function reserve(int $zoneId, int $flavorId, string $key, int $quantity, \DateTimeImmutable $expires): array
    {
        if ($quantity < 1 || $quantity > 100 || !preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $key) || $expires <= new \DateTimeImmutable()) $this->fail('库存预占数量、标识或有效期无效');
        return Db::transaction(function () use ($zoneId, $flavorId, $key, $quantity, $expires): array {
            $pool = $this->lockPool($zoneId, $flavorId);
            $existing = Db::table('compute_inventory_reservations')->where('reservation_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->pool_id !== (int) $pool->id || (int) $existing->quantity !== $quantity) $this->fail('预占标识已用于其他配置');
                if ($existing->status !== 'reserved' || $existing->expires_at <= date('Y-m-d H:i:s')) $this->fail('库存预占已终止或过期');
                return (array) $existing;
            }
            if ($pool->capacity !== null && $pool->capacity - $pool->reserved - $pool->occupied < $quantity) $this->fail('所选规格库存不足');
            $now = date('Y-m-d H:i:s');
            Db::table('compute_inventory_pools')->where('id', $pool->id)->increment('reserved', $quantity, ['updated_at' => $now]);
            Db::table('compute_inventory_reservations')->insert(['pool_id' => $pool->id, 'reservation_key' => $key, 'quantity' => $quantity, 'status' => 'reserved', 'expires_at' => $expires->format('Y-m-d H:i:s'), 'updated_at' => $now]);
            return (array) Db::table('compute_inventory_reservations')->where('reservation_key', $key)->first();
        });
    }

    /** @inheritDoc */
    public function transition(string $key, string $target, bool $resourceReleased = false): void
    {
        if (!in_array($target, ['occupied', 'released'], true)) $this->fail('库存状态转换无效');
        Db::transaction(function () use ($key, $target, $resourceReleased): void {
            $reference = Db::table('compute_inventory_reservations')->where('reservation_key', $key)->first();
            if (!$reference) $this->fail('库存预占不存在');
            $pool = Db::table('compute_inventory_pools')->where('id', $reference->pool_id)->lockForUpdate()->first();
            $row = Db::table('compute_inventory_reservations')->where('id', $reference->id)->lockForUpdate()->first();
            if ($row->status === $target) return;
            if ($row->status === 'released') $this->fail('已释放库存不能重新占用');
            if ($target === 'occupied' && $row->expires_at <= date('Y-m-d H:i:s')) $this->fail('库存预占已过期，需重新确认库存');
            if ($row->status === 'occupied' && !$resourceReleased) $this->fail('尚未确认供应资源释放，不能恢复库存');
            $field = $row->status === 'reserved' ? 'reserved' : 'occupied';
            if ($pool->{$field} < $row->quantity) $this->fail('库存流水与计数不一致');
            Db::table('compute_inventory_pools')->where('id', $pool->id)->decrement($field, $row->quantity);
            if ($target === 'occupied') Db::table('compute_inventory_pools')->where('id', $pool->id)->increment('occupied', $row->quantity);
            Db::table('compute_inventory_reservations')->where('id', $row->id)->update(['status' => $target, 'updated_at' => date('Y-m-d H:i:s')]);
        });
    }

    /** @inheritDoc */
    public function releaseExpired(\DateTimeImmutable $now): int
    {
        $count = 0;
        foreach (Db::table('compute_inventory_reservations')->where('status', 'reserved')->where('expires_at', '<=', $now->format('Y-m-d H:i:s'))->limit(500)->get() as $row) {
            // 重新锁定检查状态，避免与支付转换竞争后误释放正式占用。
            Db::transaction(function () use ($row, $now, &$count): void {
                $pool = Db::table('compute_inventory_pools')->where('id', $row->pool_id)->lockForUpdate()->first();
                $current = Db::table('compute_inventory_reservations')->where('id', $row->id)->lockForUpdate()->first();
                if ($current->status !== 'reserved' || $current->expires_at > $now->format('Y-m-d H:i:s')) return;
                if ($pool->reserved < $current->quantity) $this->fail('库存流水与计数不一致');
                Db::table('compute_inventory_pools')->where('id', $pool->id)->decrement('reserved', $current->quantity);
                Db::table('compute_inventory_reservations')->where('id', $current->id)->update(['status' => 'released', 'updated_at' => $now->format('Y-m-d H:i:s')]);
                $count++;
            });
        }
        return $count;
    }

    /** @param int $zoneId 可用区 @param int $flavorId 规格 @return object 事务内锁定的库存池 */
    private function lockPool(int $zoneId, int $flavorId): object
    {
        return Db::table('compute_inventory_pools')->where('zone_id', $zoneId)->where('flavor_id', $flavorId)->lockForUpdate()->first() ?? throw new BusinessException('请先配置该可用区规格库存', ApiErrorCode::CONFLICT, 409);
    }

    /** @param object $row 库存持久化行 @return array<string, int|null> 白名单库存状态 */
    private function map(object $row): array
    {
        return ['id' => (int) $row->id, 'zone_id' => (int) $row->zone_id, 'flavor_id' => (int) $row->flavor_id, 'capacity' => $row->capacity === null ? null : (int) $row->capacity,
            'reserved' => (int) $row->reserved, 'occupied' => (int) $row->occupied, 'available' => $row->capacity === null ? null : (int) $row->capacity - $row->reserved - $row->occupied, 'revision' => (int) $row->revision];
    }

    /** @param string $message 状态冲突原因 @return never */
    private function fail(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
