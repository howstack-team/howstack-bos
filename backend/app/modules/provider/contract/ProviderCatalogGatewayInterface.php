<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 首期只读连接器，不包含资源创建等外部写操作。 */
interface ProviderCatalogGatewayInterface
{
    /** @param array<string, string> $config 插件私有配置 @return void 认证成功 */
    public function test(array $config): void;
    /** @param array<string, string> $config 插件私有配置 @param string $kind 目录 @param array<string, int> $query 筛选 @return array{data: list<array<string, mixed>>, total: int, page: int} 规范目录 */
    public function catalog(array $config, string $kind, array $query): array;
}
