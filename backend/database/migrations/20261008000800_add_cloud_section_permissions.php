<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 将原页面访问范围映射到三级菜单，保留已有授权，不新增操作权限。 */
final class AddCloudSectionPermissions extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . 'admin_role_permissions');
        foreach (['deployment', 'network', 'storage', 'purchase'] as $section) {
            $previous = $section === 'purchase' ? 'admin.cloud-products.view' : 'admin.compute-resources.view';
            $code = 'admin.cloud-configuration.' . $section . '.view';
            $this->execute(
                "INSERT INTO {$table} (role_id, permission_code) SELECT source.role_id, ? FROM {$table} source WHERE source.permission_code = ? AND NOT EXISTS (SELECT 1 FROM {$table} existing WHERE existing.role_id = source.role_id AND existing.permission_code = ?)",
                [$code, $previous, $code],
            );
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('不能区分迁移授权与后续人工授权；请通过角色管理调整权限。');
    }
}
