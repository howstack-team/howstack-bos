<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 实时库存与预占独立于不可变销售版本，同一部署规格被多个方案共享。 */
final class CreateCloudInventory extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('compute_inventory_pools')) $this->table('compute_inventory_pools', ['engine' => 'InnoDB', 'comment' => '云服务器可用区规格库存池，多销售方案共享'])
            ->addColumn('zone_id', 'integer', ['signed' => false, 'comment' => '本地可用区标识'])
            ->addColumn('flavor_id', 'integer', ['signed' => false, 'comment' => '本地规格标识'])
            ->addColumn('capacity', 'integer', ['null' => true, 'signed' => false, 'comment' => '库存总配额，台；NULL表示不限制库存'])
            ->addColumn('reserved', 'integer', ['default' => 0, 'signed' => false, 'comment' => '待支付预占数量，台'])
            ->addColumn('occupied', 'integer', ['default' => 0, 'signed' => false, 'comment' => '正式占用数量，台；需确认资源释放后扣减'])
            ->addColumn('revision', 'integer', ['default' => 1, 'comment' => '配置乐观锁版本'])
            ->addColumn('updated_at', 'datetime', ['comment' => '库存最后变更时间'])
            ->addIndex(['zone_id', 'flavor_id'], ['unique' => true, 'name' => 'uk_compute_inventory_deployment'])->create();
        if (!$this->hasTable('compute_inventory_reservations')) $this->table('compute_inventory_reservations', ['engine' => 'InnoDB', 'comment' => '云服务器库存预占流水，幂等申请与状态转换'])
            ->addColumn('pool_id', 'integer', ['signed' => false, 'comment' => '库存池标识'])
            ->addColumn('reservation_key', 'string', ['limit' => 128, 'comment' => '调用方稳定幂等键；绑定订单等业务，不含敏感值'])
            ->addColumn('quantity', 'integer', ['signed' => false, 'comment' => '预占实例数量，台'])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'reserved', 'comment' => 'reserved预占、occupied占用、released释放'])
            ->addColumn('expires_at', 'datetime', ['comment' => '预占过期时间；正式占用不按此时间自动释放'])
            ->addColumn('updated_at', 'datetime', ['comment' => '预占状态最后变更时间'])
            ->addIndex('reservation_key', ['unique' => true])->addIndex(['status', 'expires_at'])->create();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('库存和预占流水不可回滚删除，请使用前向修复');
    }
}
