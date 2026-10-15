<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 宿主内部供应调用端口；Infrastructure 将其转换为公共插件 SDK v1。 */
interface ProviderAdapterInterface extends ProviderCatalogGatewayInterface
{
    /**
     * 校验供应商特有的连接规则，返回规范配置。
     * @param array<string, string|int|bool> $configuration 已经按插件字段白名单校验的配置
     * @return array<string, string|int|bool> 规范配置
     */
    public function normalizeConfiguration(array $configuration): array;

    /**
     * 校验该供应商的部署位置、目录关系及当前适配能力。
     * @param array<string, list<array<string, mixed>>> $resources 已校验的内部产品资源
     * @param array<string, array<int, string>> $mapping 内部标识与外部标识映射
     * @param array<string, array<string, array<string, mixed>>> $catalog 按目录类型与外部标识索引的目录
     * @return void
     */
    public function validateBinding(array $resources, array $mapping, array $catalog): void;
}
