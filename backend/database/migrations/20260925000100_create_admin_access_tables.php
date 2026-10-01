<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;
use app\modules\system_update\exception\SystemUpdateException;

/**
 * 为运营后台增加角色、部门和行级数据权限的存储结构。
 */
final class CreateAdminAccessTables extends AbstractMigration
{
    /**
     * 扩展管理员表并创建授权关系表。
     * @return void
     */
    public function up(): void
    {
        $usersTable = $this->getAdapter()->quoteTableName((string) $this->getAdapter()->getOption('table_prefix') . 'admin_users');
        $rows = $this->fetchAll('SELECT id FROM ' . $usersTable . ' ORDER BY id LIMIT 2');
        $founderId = count($rows) === 1 ? (int) $rows[0]['id'] : null;
        if (count($rows) > 1) {
            $selected = getenv('HLYQ_FOUNDER_ADMIN_ID');
            if (!is_string($selected) || preg_match('/^[1-9][0-9]*$/', $selected) !== 1) {
                throw new SystemUpdateException('检测到多个旧版管理员，无法自动判定创始账号；请先确认账号并设置 HLYQ_FOUNDER_ADMIN_ID 后重试', 409);
            }
            $founderId = (int) $selected;
            $match = $this->fetchRow('SELECT id FROM ' . $usersTable . ' WHERE id = ' . $founderId);
            if (!is_array($match)) {
                throw new SystemUpdateException('HLYQ_FOUNDER_ADMIN_ID 不对应现有管理员，请核实后重试', 409);
            }
        }

        $users = $this->table('admin_users');
        $usersChanged = false;
        if (!$users->hasColumn('department_id')) {
            $users->addColumn('department_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'nickname']);
            $usersChanged = true;
        }
        if (!$users->hasColumn('created_by')) {
            $users->addColumn('created_by', 'integer', ['signed' => false, 'null' => true, 'after' => 'department_id']);
            $usersChanged = true;
        }
        if (!$users->hasColumn('is_founder')) {
            $users->addColumn('is_founder', 'boolean', ['default' => false, 'after' => 'created_by']);
            $usersChanged = true;
        }
        if (!$users->hasIndexByName('idx_admin_department')) {
            $users->addIndex(['department_id'], ['name' => 'idx_admin_department']);
            $usersChanged = true;
        }
        if ($usersChanged) {
            $users->update();
        }

        // MySQL DDL 不随迁移事务回滚；重复执行时跳过已创建结构以恢复中断安装。
        if (!$this->hasTable('admin_departments')) {
            $this->table('admin_departments', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('parent_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('status', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['parent_id'], ['name' => 'idx_department_parent'])
                ->create();
        }

        if (!$this->hasTable('admin_roles')) {
            $this->table('admin_roles', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('code', 'string', ['limit' => 64])
                ->addColumn('description', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('data_scope', 'string', ['limit' => 32, 'default' => 'self'])
                ->addColumn('status', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['code'], ['unique' => true, 'name' => 'uk_admin_role_code'])
                ->create();
        }

        if (!$this->hasTable('admin_user_roles')) {
            $this->table('admin_user_roles', ['id' => false, 'primary_key' => ['admin_user_id', 'role_id'], 'engine' => 'InnoDB'])
                ->addColumn('admin_user_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
                ->addIndex(['role_id'], ['name' => 'idx_admin_user_role_role'])
                ->create();
        }
        if (!$this->hasTable('admin_role_permissions')) {
            $this->table('admin_role_permissions', ['id' => false, 'primary_key' => ['role_id', 'permission_code'], 'engine' => 'InnoDB'])
                ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('permission_code', 'string', ['limit' => 100, 'null' => false])
                ->create();
        }
        if (!$this->hasTable('admin_role_departments')) {
            $this->table('admin_role_departments', ['id' => false, 'primary_key' => ['role_id', 'department_id'], 'engine' => 'InnoDB'])
                ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('department_id', 'integer', ['signed' => false, 'null' => false])
                ->addIndex(['department_id'], ['name' => 'idx_admin_role_department'])
                ->create();
        }

        // 旧版本只存在安装账号；多个账号时不猜测创始人，避免把最高权限交给错误对象。
        if ($founderId !== null) {
            $this->execute('UPDATE ' . $usersTable . ' SET is_founder = 1 WHERE id = ' . $founderId);
        }
    }

    /**
     * 阻止回滚时删除授权数据。
     * @return void
     * @throws IrreversibleMigrationException 权限迁移不可逆时抛出
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('权限迁移不可逆，请使用前向修复迁移。');
    }
}
