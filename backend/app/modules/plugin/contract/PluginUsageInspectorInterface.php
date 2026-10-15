<?php

declare(strict_types=1);

namespace app\modules\plugin\contract;

/** 各业务模块通过公开查询提供引用数量，插件管理不读取业务内部表。 */
interface PluginUsageInspectorInterface
{
    /**
     * 在生命周期事务中核对业务引用。
     * @param string $id 插件稳定身份
     * @return array{references: int, active_references: int} 总引用与仍在接受业务的引用
     */
    public function inspect(string $id): array;
}
