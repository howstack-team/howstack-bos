<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 每个产品提供一份购买接入实现，在组合根注册；类型计价仍由独立的版本处理器负责。 */
interface ProductPurchaseHandlerInterface
{
    public function type(): string;

    /** @param array<string, mixed> $input 购买输入 @return array<string, mixed> 类型白名单配置 */
    public function clean(array $input): array;

    /** @param array<string, mixed> $snapshot 销售快照 */
    public function supportsPublic(array $snapshot): bool;

    /** @param array<string, mixed> $choice 目录项 @return array<string, mixed> 公开目录项 */
    public function publicProduct(array $choice): array;

    /** @param array<string, mixed> $resolved 类型解析结果 @return array<string, mixed> 公开选项 */
    public function publicOptions(array $resolved): array;

    /** @param array<string, mixed> $configuration 标准配置；只检查余量，不预占 */
    public function check(array $configuration): void;

    /** @param array<string, mixed> $configuration 标准配置 @param list<int> $unitIds 实例订单单元 */
    public function occupy(array $configuration, array $unitIds): void;

    public function release(int $unitId): void;
}
