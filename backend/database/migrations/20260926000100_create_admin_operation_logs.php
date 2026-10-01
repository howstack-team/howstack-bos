<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 保存管理员写操作的脱敏请求和响应快照。 */
final class CreateAdminOperationLogs extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('admin_operation_logs')) {
            return;
        }

        $this->table('admin_operation_logs', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('admin_user_id', 'integer', ['signed' => false])
            ->addColumn('operator_username', 'string', ['limit' => 32])
            ->addColumn('operator_nickname', 'string', ['limit' => 32])
            ->addColumn('module', 'string', ['limit' => 64])
            ->addColumn('operation', 'string', ['limit' => 128])
            ->addColumn('method', 'string', ['limit' => 8])
            ->addColumn('request_url', 'string', ['limit' => 1024])
            ->addColumn('status', 'integer', ['signed' => false])
            ->addColumn('http_status', 'integer', ['signed' => false])
            ->addColumn('client_ip', 'string', ['limit' => 45])
            ->addColumn('client_ip_location', 'string', ['limit' => 128])
            ->addColumn('duration_ms', 'integer', ['signed' => false])
            ->addColumn('browser', 'string', ['limit' => 64])
            ->addColumn('operating_system', 'string', ['limit' => 64])
            ->addColumn('request_id', 'string', ['limit' => 64])
            ->addColumn('request_params', 'text', ['null' => true])
            ->addColumn('request_headers', 'text', ['null' => true])
            ->addColumn('request_body', 'text', ['null' => true])
            ->addColumn('response_headers', 'text', ['null' => true])
            ->addColumn('response_body', 'text', ['null' => true])
            ->addColumn('operated_at', 'datetime')
            ->addIndex(['operated_at', 'id'], ['name' => 'idx_admin_operation_time'])
            ->addIndex(['admin_user_id', 'operated_at'], ['name' => 'idx_admin_operation_actor_time'])
            ->addIndex(['module', 'operated_at'], ['name' => 'idx_admin_operation_module_time'])
            ->create();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('操作审计日志不可回滚删除，请使用前向修复迁移。');
    }
}
