<?php

declare(strict_types=1);

namespace app\modules\website\contract;

/** 私有主题包与公开资源发布边界；路径不接受请求参数直接拼接。 */
interface ThemeRegistryInterface
{
    /** 读取经过验证的主题包，不含数据库和网络操作。
     * @return array<string, mixed> 清单、设置声明、默认值及包摘要
     */
    public function read(string $id): array;

    /** 按摘要读取不可变模板快照；未发布时从匹配源码构建。
     * @return array<string, mixed> 包资料及私有模板目录
     */
    public function pinned(string $id, string $version, string $digest): array;

    /** 枚举本地源码主题；已安装目录和启用状态由官网仓储维护。
     * @return list<array<string, mixed>> 已校验清单
     */
    public function list(): array;
    /** 原子发布已校验源码与公开静态资源，保留旧摘要快照。
     * @param array<string, mixed> $package 已校验包
     */
    public function publish(array $package): void;
}
