<?php

declare(strict_types=1);

use app\modules\admin\access\domain\SystemAccessDefaults;
use app\modules\system_update\exception\SystemUpdateException;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 初始化不可删除的超级管理员角色及唯一根部门，并兼容已安装系统。
 */
final class SeedAdminSystemDefaults extends AbstractMigration
{
    /**
     * 扩展系统标记、创建默认数据并将旧根部门收拢到唯一根节点。
     * @return void
     * @throws SystemUpdateException 系统角色标识被自定义角色占用时抛出
     */
    public function up(): void
    {
        $roles = $this->table('admin_roles');
        if (!$roles->hasColumn('is_system')) {
            $roles->addColumn('is_system', 'boolean', ['default' => false, 'after' => 'status'])->update();
        }

        $departments = $this->table('admin_departments');
        $departmentsChanged = false;
        if (!$departments->hasColumn('system_key')) {
            $departments->addColumn('system_key', 'string', ['limit' => 32, 'null' => true, 'after' => 'name']);
            $departmentsChanged = true;
        }
        if (!$departments->hasIndexByName('uk_department_system_key')) {
            $departments->addIndex(['system_key'], ['unique' => true, 'name' => 'uk_department_system_key']);
            $departmentsChanged = true;
        }
        if ($departmentsChanged) {
            $departments->update();
        }

        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $departmentsTable = $this->getAdapter()->quoteTableName($prefix . 'admin_departments');
        $rolesTable = $this->getAdapter()->quoteTableName($prefix . 'admin_roles');
        $usersTable = $this->getAdapter()->quoteTableName($prefix . 'admin_users');
        $userRolesTable = $this->getAdapter()->quoteTableName($prefix . 'admin_user_roles');

        $root = $this->fetchRow("SELECT id FROM {$departmentsTable} WHERE system_key = '" . SystemAccessDefaults::ROOT_DEPARTMENT_KEY . "'");
        if (!is_array($root)) {
            $this->execute("INSERT INTO {$departmentsTable} (name, system_key, parent_id, status, created_at, updated_at)
                VALUES ('总部', '" . SystemAccessDefaults::ROOT_DEPARTMENT_KEY . "', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $root = $this->fetchRow("SELECT id FROM {$departmentsTable} WHERE system_key = '" . SystemAccessDefaults::ROOT_DEPARTMENT_KEY . "'");
        }
        if (!is_array($root)) {
            throw new SystemUpdateException('系统根部门初始化失败，请检查数据库状态后重试', 500);
        }
        $rootId = (int) $root['id'];
        $this->execute("UPDATE {$departmentsTable} SET parent_id = {$rootId} WHERE parent_id IS NULL AND id <> {$rootId}");
        $this->execute("UPDATE {$usersTable} SET department_id = {$rootId} WHERE is_founder = 1 AND department_id IS NULL");

        $role = $this->fetchRow("SELECT id, is_system FROM {$rolesTable} WHERE code = '" . SystemAccessDefaults::SUPER_ADMIN_ROLE_CODE . "'");
        if (is_array($role) && (int) $role['is_system'] !== 1) {
            throw new SystemUpdateException('系统保留角色标识已被自定义角色占用，请先处理冲突后重试', 409);
        }
        if (!is_array($role)) {
            $this->execute("INSERT INTO {$rolesTable} (name, code, description, data_scope, status, is_system, created_at, updated_at)
                VALUES ('超级管理员', '" . SystemAccessDefaults::SUPER_ADMIN_ROLE_CODE . "', '系统默认角色，拥有全部权限', 'all', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $role = $this->fetchRow("SELECT id FROM {$rolesTable} WHERE code = '" . SystemAccessDefaults::SUPER_ADMIN_ROLE_CODE . "'");
        }
        if (!is_array($role)) {
            throw new SystemUpdateException('系统默认角色初始化失败，请检查数据库状态后重试', 500);
        }
        $roleId = (int) $role['id'];
        foreach ($this->fetchAll("SELECT id FROM {$usersTable} WHERE is_founder = 1") as $founder) {
            $founderId = (int) $founder['id'];
            $assigned = $this->fetchRow("SELECT role_id FROM {$userRolesTable} WHERE admin_user_id = {$founderId} AND role_id = {$roleId}");
            if (!is_array($assigned)) {
                $this->execute("INSERT INTO {$userRolesTable} (admin_user_id, role_id) VALUES ({$founderId}, {$roleId})");
            }
        }
    }

    /**
     * 阻止回滚时移除系统角色和根部门。
     * @return void
     * @throws IrreversibleMigrationException 系统默认数据不可逆时抛出
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('系统默认授权迁移不可逆，请使用前向修复迁移。');
    }
}
