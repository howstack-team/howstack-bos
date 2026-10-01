<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 固定首次交付来源，保留后续订单到同一服务的多次关联。 */
final class LinkServiceOriginOrderUnit extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $services = '`' . $prefix . 'service_instances`';
        $units = '`' . $prefix . 'order_item_units`';
        $items = '`' . $prefix . 'order_items`';
        $orders = '`' . $prefix . 'orders`';
        $invalid = $this->fetchRow("SELECT u.id FROM $units u LEFT JOIN $services s ON s.id = u.service_id
            LEFT JOIN $items i ON i.id = u.order_item_id LEFT JOIN $orders o ON o.id = i.order_id
            WHERE u.service_id IS NOT NULL AND (s.id IS NULL OR i.id IS NULL OR o.id IS NULL OR s.member_id <> o.member_id) LIMIT 1");
        if ($invalid !== false) {
            throw new RuntimeException('服务订单关联无效，请核查订单单位 ' . $invalid['id'] . '；迁移未修补历史数据。');
        }
        $table = $this->table('service_instances');
        $hasOrigin = $table->hasColumn('origin_order_unit_id');
        if ($hasOrigin) {
            $invalid = $this->fetchRow("SELECT s.id FROM $services s LEFT JOIN $units u ON u.id = s.origin_order_unit_id
                WHERE s.origin_order_unit_id IS NOT NULL AND (u.id IS NULL OR u.service_id IS NULL OR u.service_id <> s.id) LIMIT 1");
            if ($invalid !== false) throw new RuntimeException('服务首次来源无效，请核查服务 ' . $invalid['id']);
        }
        $condition = $hasOrigin ? ' AND s.origin_order_unit_id IS NULL' : '';
        $ambiguous = $this->fetchRow("SELECT s.id FROM $services s JOIN $units u ON u.service_id = s.id
            WHERE 1 = 1 $condition GROUP BY s.id HAVING COUNT(*) > 1 LIMIT 1");
        if ($ambiguous !== false) throw new RuntimeException('服务存在多笔交易但缺少首次来源，请核查服务 ' . $ambiguous['id']);
        if (!$hasOrigin) {
            $table->addColumn('origin_order_unit_id', 'integer', ['signed' => false, 'null' => true])->update();
        }
        if (!$table->hasIndexByName('uk_service_origin_order_unit')) {
            $table->addIndex(['origin_order_unit_id'], ['unique' => true, 'name' => 'uk_service_origin_order_unit'])->update();
        }
        $this->execute("UPDATE $services SET origin_order_unit_id = (SELECT u.id FROM $units u WHERE u.service_id = $services.id)
            WHERE origin_order_unit_id IS NULL AND EXISTS (SELECT 1 FROM $units u WHERE u.service_id = $services.id)");
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite' && !$table->hasForeignKey('origin_order_unit_id')) {
            $table->addForeignKey('origin_order_unit_id', 'order_item_units', 'id', [
                'constraint' => $prefix . 'fk_service_origin_unit', 'delete' => 'RESTRICT', 'update' => 'RESTRICT',
            ])->update();
        }
        $unitTable = $this->table('order_item_units');
        // 先建普通索引，再移除唯一索引，保持已有 MySQL 外键的索引依赖。
        if (!$unitTable->hasIndexByName('idx_order_unit_service')) {
            $unitTable->addIndex(['service_id'], ['name' => 'idx_order_unit_service'])->update();
        }
        if ($unitTable->hasIndexByName('uk_order_unit_service')) {
            $unitTable->removeIndexByName('uk_order_unit_service')->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('同一服务可能已有多次交易，不能恢复唯一限制；请使用前向修复。');
    }
}
