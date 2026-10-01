<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 为已交付资源建立公共实例身份，产品类型细节仍由各类型域维护。 */
final class CreateServiceInstances extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('service_instances')) {
            $this->table('service_instances', [
                'id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('instance_no', 'string', ['limit' => 48])
                ->addColumn('product_type', 'string', ['limit' => 64])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('display_name', 'string', ['limit' => 128, 'default' => ''])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('activated_at', 'datetime', ['null' => true])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['instance_no'], ['unique' => true, 'name' => 'uk_service_instance_no'])
                ->addIndex(['product_type', 'id'], ['name' => 'idx_service_type_id'])
                ->addIndex(['member_id', 'id'], ['name' => 'idx_service_member_id'])
                ->addIndex(['status', 'expires_at'], ['name' => 'idx_service_status_expiry'])
                ->create();
        }

        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $prefix = (string) $this->getAdapter()->getOption('table_prefix');
            $instances = $this->table('service_instances');
            if (!$instances->hasForeignKey('member_id')) {
                $instances->addForeignKey('member_id', 'members', 'id', [
                    'constraint' => $prefix . 'fk_service_member', 'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                ])->update();
            }
            $units = $this->table('order_item_units');
            if (!$units->hasForeignKey('service_id')) {
                $units->addForeignKey('service_id', 'service_instances', 'id', [
                    'constraint' => $prefix . 'fk_order_unit_service', 'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                ])->update();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('服务实例迁移不可逆，请使用前向修复迁移。');
    }
}
