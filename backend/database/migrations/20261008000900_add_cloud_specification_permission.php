<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 将原部署与规格页面的查看权限拆分给新增规格与套餐页面。 */
final class AddCloudSpecificationPermission extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . 'admin_role_permissions');
        $this->execute("INSERT INTO {$table} (role_id, permission_code) SELECT source.role_id, ? FROM {$table} source WHERE source.permission_code = ? AND NOT EXISTS (SELECT 1 FROM {$table} existing WHERE existing.role_id = source.role_id AND existing.permission_code = ?)",
            ['admin.cloud-configuration.specification.view', 'admin.cloud-configuration.deployment.view', 'admin.cloud-configuration.specification.view']);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('请通过角色管理调整权限，避免删除后续人工授权。');
    }
}
