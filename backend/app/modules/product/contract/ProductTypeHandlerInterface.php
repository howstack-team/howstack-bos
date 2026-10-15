<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 产品类型版本化契约；公共销售层不解释规格、磁盘等类型字段。 */
interface ProductTypeHandlerInterface
{
    /** @param string $version 快照契约版本 @return bool 是否明确支持兼容读取。 */
    public function supportsVersion(string $version): bool;
    /** @return string 稳定类型标识 */
    public function type(): string;
    /** @return string 配置契约版本 */
    public function version(): string;
    /** @param array<string, mixed> $draft 已验证草稿 @param array<string, list<array<string, mixed>>> $resources 资源快照 @return array<string, mixed> 已完整校验销售快照 */
    public function snapshot(array $draft, array $resources): array;
    /** @param array<string, mixed> $snapshot 销售快照 @param array<string, mixed> $selection 当前选择 @return array<string, mixed> 可选值与失效字段 */
    public function resolve(array $snapshot, array $selection): array;
    /** @param array<string, mixed> $snapshot 销售快照 @param array<string, mixed> $selection 完整选择 @return array<string, mixed> 标准配置及整数分计价 */
    public function price(array $snapshot, array $selection): array;
    /** @param array<string, mixed> $snapshot 销售快照 @param array<string, mixed> $selection 购买选择 @return array<string, mixed> 经完整校验的标准交付配置，不含凭据。 */
    public function provisioningConfiguration(array $snapshot, array $selection): array;
}
