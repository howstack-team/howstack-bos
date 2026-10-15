<?php

declare(strict_types=1);

namespace app\modules\website\contract;

/** 模板渲染边界，业务用例只交付匿名公开数组。 */
interface WebsiteRendererInterface
{
    /** @param array<string, mixed> $package 不可变主题包
     * @param array<string, mixed> $context 匿名渲染数据
     * @return string 转义后的 HTML
     */
    public function render(array $package, string $template, array $context, array $routes): string;
}
