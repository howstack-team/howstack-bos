<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 保存管理员登录成功与失败的环境快照。 */
final class CreateAdminLoginLogs extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('admin_login_logs')) {
            return;
        }
        $this->table('admin_login_logs', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('admin_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('username', 'string', ['limit' => 32])
            ->addColumn('nickname', 'string', ['limit' => 32])
            ->addColumn('status', 'integer', ['signed' => false])
            ->addColumn('client_ip', 'string', ['limit' => 45])
            ->addColumn('client_ip_location', 'string', ['limit' => 128])
            ->addColumn('browser', 'string', ['limit' => 64])
            ->addColumn('operating_system', 'string', ['limit' => 64])
            ->addColumn('failure_reason', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('logged_at', 'datetime')
            ->addIndex(['logged_at', 'id'], ['name' => 'idx_admin_login_logged_at'])
            ->addIndex(['admin_user_id', 'logged_at'], ['name' => 'idx_admin_login_user_time'])
            ->addIndex(['client_ip', 'logged_at'], ['name' => 'idx_admin_login_ip_time'])
            ->create();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('登录审计日志不可回滚删除，请使用前向修复迁移。');
    }
}
