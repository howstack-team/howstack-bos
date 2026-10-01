<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 创建订单主表和不可随商品配置变化的订单项快照。 */
final class CreateOrdersAndOrderItems extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('orders')) {
            $this->table('orders', [
                'id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('order_no', 'string', ['limit' => 40])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('member_username_snapshot', 'string', ['limit' => 32])
                ->addColumn('member_nickname_snapshot', 'string', ['limit' => 64])
                ->addColumn('order_type', 'string', ['limit' => 24])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('payment_status', 'string', ['limit' => 24])
                ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'CNY'])
                ->addColumn('subtotal_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('discount_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('payable_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('paid_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('refunded_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('remark', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('paid_at', 'datetime', ['null' => true])
                ->addColumn('completed_at', 'datetime', ['null' => true])
                ->addColumn('cancelled_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['order_no'], ['unique' => true, 'name' => 'uk_order_no'])
                ->addIndex(['member_id', 'created_at'], ['name' => 'idx_order_member_time'])
                ->addIndex(['status', 'created_at'], ['name' => 'idx_order_status_time'])
                ->addIndex(['created_at'], ['name' => 'idx_order_created_at'])
                ->create();
        }

        if (!$this->hasTable('order_items')) {
            $this->table('order_items', [
                'id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('order_id', 'integer', ['signed' => false])
                ->addColumn('line_no', 'integer', ['signed' => false])
                ->addColumn('product_type', 'string', ['limit' => 64])
                ->addColumn('product_type_version', 'string', ['limit' => 32])
                ->addColumn('sales_version', 'string', ['limit' => 64])
                ->addColumn('product_name', 'string', ['limit' => 128])
                ->addColumn('configuration_snapshot', 'text', ['null' => true])
                ->addColumn('quantity', 'integer', ['signed' => false])
                ->addColumn('unit_price_minor', 'biginteger', ['signed' => false])
                ->addColumn('total_minor', 'biginteger', ['signed' => false])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['order_id', 'line_no'], ['unique' => true, 'name' => 'uk_order_item_line'])
                ->create();
        }

        // SQLite 仅用于迁移测试；MySQL 使用外键保证历史订单和订单项不被孤立删除。
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $prefix = (string) $this->getAdapter()->getOption('table_prefix');
            $orders = $this->table('orders');
            if (!$orders->hasForeignKey('member_id')) {
                $orders->addForeignKey('member_id', 'members', 'id', [
                    'constraint' => $prefix . 'fk_order_member', 'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                ])->update();
            }
            $items = $this->table('order_items');
            if (!$items->hasForeignKey('order_id')) {
                $items->addForeignKey('order_id', 'orders', 'id', [
                    'constraint' => $prefix . 'fk_order_item_order', 'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                ])->update();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('订单及订单项迁移不可逆，请使用前向修复迁移。');
    }
}
