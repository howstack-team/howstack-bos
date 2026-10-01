<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 会员以手机号作为登录标识；保留独立会员 ID。 */
final class RemoveMemberUsernames extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $members = $prefix . 'members';
        $orders = $prefix . 'orders';
        $missing = (int) $this->fetchRow("SELECT COUNT(*) AS total FROM {$members} WHERE mobile IS NULL OR mobile = ''")['total'];
        if ($missing !== 0) {
            throw new RuntimeException("有 {$missing} 位会员尚未填写手机号；请先完成手机号绑定，再执行删除用户名迁移。");
        }

        // 历史订单展示当时所属会员的手机号，不复制旧用户名到新字段。
        $this->table('orders')->addColumn('member_mobile_snapshot', 'string', ['limit' => 32, 'default' => ''])->update();
        $this->execute("UPDATE {$orders} SET member_mobile_snapshot = (SELECT mobile FROM {$members} WHERE {$members}.id = {$orders}.member_id)");
        $this->table('orders')->removeColumn('member_username_snapshot')->update();

        $this->table('members')->removeIndex(['username'])->removeColumn('username')->update();
        $this->table('members')->changeColumn('mobile', 'string', ['limit' => 32, 'null' => false])->update();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('会员用户名已移除，请使用备份和前向修复迁移恢复。');
    }
}
