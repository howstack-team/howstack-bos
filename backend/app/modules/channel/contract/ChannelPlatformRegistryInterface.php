<?php

declare(strict_types=1);

namespace app\modules\channel\contract;

/** 通道目录与运行平台边界；配置保存和执行必须核对安装、启用及包摘要。 */
interface ChannelPlatformRegistryInterface
{
    /**
     * 所有经过宿主校验的本地平台，包括尚未安装或停用的平台。
     * @param string $category 已验证类别
     * @return list<array<string, mixed>> 公开声明及 platform_available
     */
    public function catalog(string $category): array;

    /**
     * @param string $category 已验证类别
     * @return list<array<string, mixed>> 不含内部路径、入口及密钥的配置声明
     */
    public function definitions(string $category): array;

    /**
     * @param string $category 已验证类别
     * @param string $id 插件稳定标识
     * @return array<string, mixed>|null 可用声明；类别不符或未启用时为NULL
     */
    public function definition(string $category, string $id): ?array;

    /**
     * 调用固定的无副作用配置校验；不测试连接或触发业务执行。
     * @param string $category 已验证类别
     * @param string $id 插件稳定标识
     * @param array<string, string|int|bool> $values 宿主已验证配置
     * @return array<string, string|int|bool> 插件规范化后的配置
     */
    public function normalize(string $category, string $id, array $values): array;
}
