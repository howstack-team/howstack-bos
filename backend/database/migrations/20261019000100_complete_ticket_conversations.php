<?php

declare(strict_types=1);

use Phinx\Migration\{AbstractMigration, IrreversibleMigrationException};

/** 工单沟通增加本人服务关联和持久幂等，不改历史正文及状态。 */
final class CompleteTicketConversations extends AbstractMigration
{
    /** @return void 只扩展结构，支持中断后重试 */
    public function up(): void
    {
        $tickets = $this->table('tickets');
        if (!$tickets->hasColumn('service_id')) {
            $tickets->addColumn('service_id', 'integer', ['signed' => false, 'null' => true, 'comment' => '关联本人云服务器内部ID；NULL表示不关联，历史工单不回填'])
                ->addIndex(['service_id'], ['name' => 'idx_ticket_service'])->update();
        }
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite' && !$tickets->hasForeignKey('service_id')) {
            $tickets->addForeignKey('service_id', 'service_instances', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT',
                'constraint' => $this->getAdapter()->getOption('table_prefix') . 'fk_ticket_service'])->update();
        }
        if (!$this->hasTable('ticket_commands')) {
            $this->table('ticket_commands', ['id' => false, 'primary_key' => ['id'], 'comment' => '工单写操作幂等结果，不保存正文', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'comment' => '工单幂等命令内部自增ID'])
                ->addColumn('actor_type', 'string', ['limit' => 8, 'comment' => '操作主体类型：member会员或admin管理员'])
                ->addColumn('actor_id', 'integer', ['signed' => false, 'comment' => '对应会员或管理员内部ID；与主体类型共同标识操作者'])
                ->addColumn('request_key', 'string', ['limit' => 64, 'comment' => '客户端操作幂等键，同主体同键只执行一次'])
                ->addColumn('action', 'string', ['limit' => 8, 'comment' => 'create新建/reply追问或回复/close关闭'])
                ->addColumn('payload_hash', 'string', ['limit' => 64, 'comment' => '规范化动作、目标及请求内容SHA256摘要，不存明文正文'])
                ->addColumn('ticket_id', 'integer', ['signed' => false, 'null' => true, 'comment' => '已提交工单ID；事务执行中为NULL，成功后必有值'])
                ->addColumn('message_id', 'integer', ['signed' => false, 'null' => true, 'comment' => '本次会话消息ID；新建或关闭为NULL'])
                ->addColumn('created_at', 'datetime', ['comment' => '命令受理时间，业务时区'])
                ->addIndex(['actor_type', 'actor_id', 'request_key'], ['unique' => true, 'name' => 'uk_ticket_command_actor_key'])
                ->addIndex(['ticket_id'], ['name' => 'idx_ticket_command_ticket'])->create();
        }
    }

    /** @return void 不允许删除会话历史 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('工单历史及幂等结果只能前向修复');
    }
}
