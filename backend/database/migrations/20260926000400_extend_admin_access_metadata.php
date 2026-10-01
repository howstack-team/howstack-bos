<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 扩展后台用户联系方式，以及部门和角色的排序与说明。
 */
final class ExtendAdminAccessMetadata extends AbstractMigration
{
    /**
     * 为现有记录提供兼容默认值，新增联系信息保持可空。
     * @return void
     */
    public function change(): void
    {
        $this->table('admin_users')
            ->addColumn('mobile', 'string', ['limit' => 32, 'null' => true, 'after' => 'employee_no'])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true, 'after' => 'mobile'])
            ->addColumn('description', 'string', ['limit' => 255, 'default' => '', 'after' => 'email'])
            ->update();

        $this->table('admin_departments')
            ->addColumn('sort_order', 'integer', ['signed' => false, 'default' => 0, 'after' => 'name'])
            ->addColumn('description', 'string', ['limit' => 255, 'default' => '', 'after' => 'sort_order'])
            ->addIndex(['sort_order'], ['name' => 'idx_admin_department_sort'])
            ->update();

        $this->table('admin_roles')
            ->addColumn('sort_order', 'integer', ['signed' => false, 'default' => 0, 'after' => 'code'])
            ->addIndex(['sort_order'], ['name' => 'idx_admin_role_sort'])
            ->update();
    }
}
