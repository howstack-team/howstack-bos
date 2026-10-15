<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;
use Phinx\Db\Adapter\MysqlAdapter;

/** 参数规则与资源对应一起封存在供应绑定中。 */
final class SealProviderParameterMapping extends AbstractMigration
{
    /** @return void 幂等扩展，不重写历史绑定 */
    public function up(): void
    {
        $table = $this->table('provider_bindings');
        if (!$table->hasColumn('parameter_mapping')) $table->addColumn('parameter_mapping', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG, 'comment' => '发布时封存的标准字段到供应参数对应，包含单位枚举转换；历史NULL使用原插件转换'])->update();
    }

    /** @return void @throws IrreversibleMigrationException 只能前向修复 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('历史供应绑定不可回滚删除');
    }
}
