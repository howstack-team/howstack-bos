<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 已安装并启用的供应适配器插件目录。 */
interface ProviderAdapterRegistryInterface
{
    /** @return list<array<string, mixed>> 不含凭据或可执行入口的插件资料 */
    public function definitions(bool $includeDraining = false): array;

    /** @param string $id 稳定适配器标识 @return array<string, mixed>|null 插件资料，不可用时为空 */
    public function definition(string $id): ?array;

    /** @param string $id 稳定适配器标识 @return ProviderAdapterInterface 插件实例，不可用时拒绝调用 */
    public function get(string $id): ProviderAdapterInterface;
}
