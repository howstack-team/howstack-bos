<?php

declare(strict_types=1);

namespace app\modules\product\contract;

interface CloudDeliveryRuleRepositoryInterface
{
    /** @return list<array<string, mixed>> 当前产品的交付规则。 */
    public function all(): array;
    /** @param int $id 规则标识 @return array<string, mixed>|null 规则详情。 */
    public function find(int $id): ?array;
    /** @param int $id 规则标识 @return array<string, mixed>|null 事务内锁定规则，发布期间禁止修改。 */
    public function lock(int $id): ?array;
    /** @param int|null $id 规则标识 @param array<string, mixed> $data 白名单配置 @return array<string, mixed> 已保存规则。 */
    public function save(?int $id, array $data): array;
}
