<?php

declare(strict_types=1);

namespace app\modules\system_settings\contract;

/** 分类与设置值的持久化边界；不包含动态表单定义。 */
interface SystemSettingsRepositoryInterface
{
    /**
     * 查询可管理分类，按排序值和标识排序。
     * @return list<array{id: int, code: string, name: string, sort_order: int}> 分类
     */
    public function categories(): array;

    /**
     * 读取一个分类的原始设置值。
     * @param string $category 固定分类编码
     * @return array<string, string> 点分设置键到原始值的映射
     */
    public function values(string $category): array;

    /**
     * 原子保存一个分类，保留设置项既有公开属性。
     * @param string                $category 固定分类编码
     * @param array<string, string> $values 已校验的点分设置键和值
     * @return void
     */
    public function save(string $category, array $values): void;
}
