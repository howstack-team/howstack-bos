<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 移除操作审计中无必要且可能包含敏感信息的完整 HTTP 报文。 */
final class MinimizeAdminOperationLogs extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('admin_operation_logs')) {
            return;
        }

        $table = $this->table('admin_operation_logs');
        $changed = false;
        foreach (['request_headers', 'request_body', 'response_headers', 'response_body'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('完整 HTTP 报文已从操作日志中移除，不可从审计表恢复。');
    }
}
