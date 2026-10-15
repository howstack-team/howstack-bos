<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 扩展接收组快照；不改历史请求标识、投递状态、密文或已读状态。 */
final class AddMessageRecipientGroups extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('message_recipients');
        if (!$table->hasColumn('group_key')) {
            $table->addColumn('group_key', 'string', ['limit' => 40, 'default' => 'legacy',
                'comment' => '事件发生时的接收组稳定标识；legacy表示升级前的接收对象']);
        }
        if (!$table->hasColumn('group_name')) {
            $table->addColumn('group_name', 'string', ['limit' => 64, 'default' => '',
                'comment' => '事件发生时的接收组名称快照；空值表示历史配置未记录名称']);
        }
        $table->update();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('接收组流水快照不可删除，请使用前向修复');
    }
}
