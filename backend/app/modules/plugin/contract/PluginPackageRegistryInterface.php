<?php

declare(strict_types=1);

namespace app\modules\plugin\contract;

/** 只读包注册快照；内部路径及入口不能直接作为 HTTP 响应。 */
interface PluginPackageRegistryInterface
{
    /** @return array<string, array<string, mixed>> 校验通过的包 */
    public function packages(): array;

    /** @return list<array{id: string, category: string, message: string}> 单包加载失败资料 */
    public function failures(): array;
}
