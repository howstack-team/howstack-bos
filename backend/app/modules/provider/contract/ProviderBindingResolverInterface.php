<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 销售发布通过此门面取得已校验供应映射，不直接访问连接模型。 */
interface ProviderBindingResolverInterface
{
    /** @param int $connectionId 连接 @param array<string, list<array<string, mixed>>> $resources 发布资源 @return array{connection_id: int, mapping: array<string, mixed>, capability_snapshot: array<string, mixed>} 固定绑定 */
    public function resolve(int $connectionId, array $resources): array;
}
