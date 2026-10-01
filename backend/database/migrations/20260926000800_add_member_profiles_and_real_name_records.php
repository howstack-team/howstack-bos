<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 增加会员分组、等级和实名认证历史记录。 */
final class AddMemberProfilesAndRealNameRecords extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('member_groups')) {
            $this->table('member_groups', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('code', 'string', ['limit' => 64])
                ->addColumn('sort_order', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('description', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('status', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('created_at', 'datetime')->addColumn('updated_at', 'datetime')
                ->addIndex(['code'], ['unique' => true, 'name' => 'uk_member_group_code'])
                ->addIndex(['sort_order'], ['name' => 'idx_member_group_sort'])->create();
        }
        if (!$this->hasTable('member_levels')) {
            $this->table('member_levels', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('code', 'string', ['limit' => 64])
                ->addColumn('level_order', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('growth_threshold', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('description', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('status', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('created_at', 'datetime')->addColumn('updated_at', 'datetime')
                ->addIndex(['code'], ['unique' => true, 'name' => 'uk_member_level_code'])
                ->addIndex(['level_order'], ['name' => 'idx_member_level_order'])->create();
        }
        $members = $this->table('members');
        if (!$members->hasColumn('group_id')) {
            $members->addColumn('group_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'nickname'])
                ->addIndex(['group_id'], ['name' => 'idx_member_group']);
        }
        if (!$members->hasColumn('level_id')) {
            $members->addColumn('level_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'group_id'])
                ->addIndex(['level_id'], ['name' => 'idx_member_level']);
        }
        $members->update();

        if (!$this->hasTable('member_real_name_authentications')) {
            $this->table('member_real_name_authentications', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('authentication_type', 'string', ['limit' => 16])
                ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending'])
                ->addColumn('subject_name', 'string', ['limit' => 128])
                ->addColumn('id_type', 'string', ['limit' => 32, 'default' => 'identity_card'])
                ->addColumn('id_number_ciphertext', 'text')
                ->addColumn('id_number_hash', 'string', ['limit' => 64])
                ->addColumn('id_number_masked', 'string', ['limit' => 64])
                ->addColumn('company_name', 'string', ['limit' => 128, 'null' => true])
                ->addColumn('registration_code_ciphertext', 'text', ['null' => true])
                ->addColumn('registration_code_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('registration_code_masked', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('legal_representative', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('upgrade_from_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('reviewed_by', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('rejection_reason', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('submitted_at', 'datetime')->addColumn('reviewed_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')->addColumn('updated_at', 'datetime')
                ->addIndex(['member_id'], ['name' => 'idx_real_name_member'])
                ->addIndex(['status', 'submitted_at'], ['name' => 'idx_real_name_status_time'])
                ->addIndex(['authentication_type'], ['name' => 'idx_real_name_type'])
                ->addIndex(['id_number_hash'], ['name' => 'idx_real_name_id_hash'])
                ->addIndex(['registration_code_hash'], ['name' => 'idx_real_name_registration_hash'])
                ->create();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('会员分组、等级和实名认证记录迁移不可逆，请使用前向修复迁移。');
    }
}
