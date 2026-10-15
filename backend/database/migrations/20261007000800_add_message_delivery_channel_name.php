<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 保存投递绑定的平台名称；历史记录留空，不从当前配置补写。 */
final class AddMessageDeliveryChannelName extends AbstractMigration
{
    /** @return void 幂等添加公开名称快照，不回填历史值 */
    public function up(): void
    {
        $table = $this->table('message_deliveries');
        if (!$table->hasColumn('channel_name')) {
            $table->addColumn('channel_name', 'string', ['limit' => 128, 'default' => '',
                'comment' => '任务创建时绑定的公开平台名称快照；空值表示历史记录或未指定通道，不含凭据']);
            $table->update();
        }
    }

    /**
     * 禁止删除历史快照。
     * @return void
     * @throws IrreversibleMigrationException 始终要求前向修复
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('通道名称流水快照不可删除，请使用前向修复');
    }
}
