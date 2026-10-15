<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

/** 当前部署位置的标准资源及映射，插件不得自行查询核心表。 */
final readonly class ProviderBinding
{
    /**
     * 接收类型模块已限定范围的绑定资料。
     * @param array<string, list<array<string, mixed>>> $resources 资源资料，不含凭据
     * @param array<string, array<int, string>> $mapping 本地标识到外部标识
     * @param array<string, array<string, array<string, mixed>>> $catalog 已缓存目录
     */
    public function __construct(public array $resources, public array $mapping, public array $catalog) {}
}
