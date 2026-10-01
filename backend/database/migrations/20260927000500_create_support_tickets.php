<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 工单分类、快捷回复与工单记录的首期管理结构。 */
final class CreateSupportTickets extends AbstractMigration
{
    public function up(): void
    {
        $options = ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];
        if (!$this->hasTable('ticket_types')) {
            $this->table('ticket_types', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('code', 'string', ['limit' => 64])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['code'], ['unique' => true, 'name' => 'uk_ticket_type_code'])
                ->create();
        }
        if (!$this->hasTable('ticket_quick_replies')) {
            $this->table('ticket_quick_replies', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('title', 'string', ['limit' => 100])
                ->addColumn('content', 'text')
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->create();
        }
        if (!$this->hasTable('tickets')) {
            $this->table('tickets', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('ticket_no', 'string', ['limit' => 48])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('type_id', 'integer', ['signed' => false])
                ->addColumn('subject', 'string', ['limit' => 200])
                ->addColumn('content', 'text')
                ->addColumn('priority', 'string', ['limit' => 16, 'default' => 'normal'])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'open'])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addColumn('closed_at', 'datetime', ['null' => true])
                ->addIndex(['ticket_no'], ['unique' => true, 'name' => 'uk_ticket_no'])
                ->addIndex(['member_id', 'id'], ['name' => 'idx_ticket_member'])
                ->addIndex(['status', 'id'], ['name' => 'idx_ticket_status'])
                ->create();
        }
        if (!$this->hasTable('ticket_messages')) {
            $this->table('ticket_messages', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('ticket_id', 'integer', ['signed' => false])
                ->addColumn('author_type', 'string', ['limit' => 16])
                ->addColumn('author_id', 'integer', ['signed' => false])
                ->addColumn('content', 'text')
                ->addColumn('created_at', 'datetime')
                ->addIndex(['ticket_id', 'id'], ['name' => 'idx_ticket_message'])
                ->create();
        }
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $prefix = (string) $this->getAdapter()->getOption('table_prefix');
            foreach ([['tickets', 'member_id', 'members'], ['tickets', 'type_id', 'ticket_types'],
                ['ticket_messages', 'ticket_id', 'tickets']] as [$name, $column, $parent]) {
                $table = $this->table($name);
                if (!$table->hasForeignKey($column)) {
                    $table->addForeignKey($column, $parent, 'id', [
                        'constraint' => $prefix . 'fk_' . $name . '_' . $column,
                        'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                    ])->update();
                }
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('工单数据迁移不可逆，请使用前向修复迁移。');
    }
}
