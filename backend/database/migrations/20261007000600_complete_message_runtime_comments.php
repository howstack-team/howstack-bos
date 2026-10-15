<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** 前向补全开发期消息状态字段的中文原生注释，不修改业务记录。 */
final class CompleteMessageRuntimeComments extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getOption('adapter') === 'mysql' && $this->hasTable('message_deliveries')) {
            $this->table('message_deliveries')->changeColumn('status', 'string', ['limit' => 24, 'default' => 'pending',
                'comment' => '投递状态：pending、processing、accepted、delivered、failed、unknown'])->update();
        }
    }

    public function down(): void
    {
        // 注释完善无需回退。
    }
}
