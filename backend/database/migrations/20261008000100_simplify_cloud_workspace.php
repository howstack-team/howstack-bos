<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 社区版固定配置入口与按可用区封存的供应绑定；不改写历史数据。 */
final class SimplifyCloudWorkspace extends AbstractMigration
{
    public function up(): void
    {
        $products = $this->table('products');
        if (!$products->hasColumn('workspace_configuration_id')) {
            $products->addColumn('workspace_configuration_id', 'biginteger', [
                'null' => true,
                'comment' => '云服务器工作台明确选用的销售配置ID；NULL未选择，历史多配置不自动合并',
            ])->update();
        }
        $bindings = $this->table('provider_bindings');
        if (!$bindings->hasColumn('zone_bindings')) {
            $bindings->addColumn('zone_bindings', 'text', [
                'null' => true,
                'limit' => MysqlAdapter::TEXT_LONG,
                'comment' => '发布时按可用区封存的供应连接、资源对应和参数规则JSON；NULL沿用历史单连接绑定',
            ])->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('配置选择与历史供应绑定只能前向修复');
    }
}
