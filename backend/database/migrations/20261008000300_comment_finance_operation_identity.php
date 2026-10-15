<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CommentFinanceOperationIdentity extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $this->table('finance_operations')->changeColumn('id', 'integer', [
                'identity' => true, 'signed' => false, 'comment' => '财务写操作审计记录标识',
            ])->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('财务审计字段说明使用前向迁移维护');
    }
}
