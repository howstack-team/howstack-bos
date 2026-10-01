<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 创建会员基础资料表。 */
final class CreateMembersTable extends AbstractMigration
{
    /** 创建会员表及唯一性索引。 */
    public function up(): void
    {
        if ($this->hasTable('members')) {
            return;
        }
        $this->table('members', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('username', 'string', ['limit' => 32])
            ->addColumn('nickname', 'string', ['limit' => 64])
            ->addColumn('mobile', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('status', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('description', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['username'], ['unique' => true, 'name' => 'uk_member_username'])
            ->addIndex(['mobile'], ['unique' => true, 'name' => 'uk_member_mobile'])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uk_member_email'])
            ->addIndex(['status'], ['name' => 'idx_member_status'])
            ->addIndex(['created_at'], ['name' => 'idx_member_created_at'])
            ->create();
    }

    /** 阻止回滚时直接删除会员资料。 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('会员数据迁移不可逆，请使用前向修复迁移。');
    }
}
