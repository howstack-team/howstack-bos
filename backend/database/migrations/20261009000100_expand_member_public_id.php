<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 扩大公开编号容量，保留历史编号、唯一索引和内部业务关联。 */
final class ExpandMemberPublicId extends AbstractMigration
{
    public function up(): void
    {
        $this->table('members')->changeColumn('public_id', 'string', [
            'limit' => 9,
            'null' => false,
            'comment' => '对外展示的九位随机数字会员编号，兼容历史八位编号；不含内部主键或注册顺序',
        ])->update();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('九位会员编号不能缩短或重新编号，请使用前向迁移。');
    }
}
