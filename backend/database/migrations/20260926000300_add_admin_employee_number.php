<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为系统用户增加可选且唯一的工号。
 */
final class AddAdminEmployeeNumber extends AbstractMigration
{
    /**
     * 增加可空工号列；MySQL 唯一索引允许多个 NULL。
     * @return void
     */
    public function change(): void
    {
        $this->table('admin_users')
            ->addColumn('employee_no', 'string', ['limit' => 32, 'null' => true, 'after' => 'nickname'])
            ->addIndex(['employee_no'], ['unique' => true, 'name' => 'uk_admin_employee_no'])
            ->update();
    }
}
