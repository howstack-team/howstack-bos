<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 前向兼容既有会员主键与财务关联，新增独立公开编号和注册校验。 */
final class AddMemberRegistration extends AbstractMigration
{
    public function up(): void
    {
        $members = $this->table('members');
        if (!$members->hasColumn('public_id')) {
            $members->addColumn('public_id', 'string', ['limit' => 8, 'null' => true, 'comment' => '对外展示的八位随机数字会员编号，不含内部主键或注册顺序'])
                ->addIndex(['public_id'], ['unique' => true, 'name' => 'uk_member_public_id'])->update();
        }
        $table = $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . 'members');
        foreach ($this->fetchAll("SELECT id FROM {$table} WHERE public_id IS NULL OR public_id = ''") as $row) {
            do {
                // 固定历史迁移的八位生成行为，避免当前领域规则变更影响旧字段回填。
                $number = (string) random_int(10000000, 99999999);
                $exists = $this->fetchRow("SELECT id FROM {$table} WHERE public_id = '{$number}'");
            } while ($exists);
            $this->execute("UPDATE {$table} SET public_id = ? WHERE id = ?", [$number, $row['id']]);
        }
        $this->table('members')->changeColumn('public_id', 'string', ['limit' => 8, 'null' => false, 'comment' => '对外展示的八位随机数字会员编号，不含内部主键或注册顺序'])
            ->changeColumn('mobile', 'string', ['limit' => 32, 'null' => true, 'comment' => '会员登录手机号；邮箱注册未绑定时为NULL，保持唯一索引'])->update();
        foreach (['mobile_verified_at' => '手机号验证码验证时间；未验证或管理员修改手机号后为NULL', 'email_verified_at' => '邮箱验证码验证时间；未验证或管理员修改邮箱后为NULL'] as $field => $comment) {
            if (!$this->table('members')->hasColumn($field)) $this->table('members')->addColumn($field, 'datetime', ['null' => true, 'comment' => $comment])->update();
        }
        if (!$this->hasTable('member_registration_challenges')) {
            $this->table('member_registration_challenges', ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci', 'comment' => '会员注册一次性验证码校验；只存摘要，不存验证码或完整接收地址'])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'comment' => '内部验证码挑战主键'])
                ->addColumn('challenge_id', 'string', ['limit' => 32, 'comment' => '随机挑战标识，返回浏览器但不提供验证码'])
                ->addColumn('method', 'string', ['limit' => 8, 'comment' => '注册方式：mobile手机号或email邮箱'])
                ->addColumn('target_hash', 'string', ['limit' => 64, 'comment' => '场景及规范化接收地址的带密钥摘要'])
                ->addColumn('binding_hash', 'string', ['limit' => 64, 'comment' => '注册浏览器临时Cookie的带密钥摘要'])
                ->addColumn('code_hash', 'string', ['limit' => 64, 'comment' => '挑战标识与六位验证码的带密钥摘要'])
                ->addColumn('status', 'string', ['limit' => 16, 'comment' => 'pending待发送、active可校验、failed失败、used已使用或superseded已替换'])
                ->addColumn('attempts', 'integer', ['default' => 0, 'comment' => '错误校验次数；最多5次'])
                ->addColumn('expires_at', 'datetime', ['comment' => '验证码到期时间；有效期300秒'])
                ->addColumn('created_at', 'datetime', ['comment' => '创建挑战时间'])
                ->addIndex(['challenge_id'], ['unique' => true])->addIndex(['target_hash', 'binding_hash'])->addIndex(['expires_at'])->create();
        }
        $q = fn (string $name): string => $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . $name);
        $categories = $q('system_setting_categories'); $settings = $q('system_settings');
        $now = date('Y-m-d H:i:s');
        if (!$this->fetchRow("SELECT id FROM {$categories} WHERE code = 'registration'")) {
            $this->execute("INSERT INTO {$categories} (code, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)", ['registration', '会员注册', 25, $now, $now]);
        }
        $id = $this->fetchRow("SELECT id FROM {$categories} WHERE code = 'registration'")['id'];
        foreach (['mobile_enabled' => '1', 'email_enabled' => '0', 'mobile_verification' => '1', 'email_verification' => '1'] as $key => $value) {
            if (!$this->fetchRow("SELECT id FROM {$settings} WHERE setting_key = 'registration.{$key}'")) {
                $this->execute("INSERT INTO {$settings} (category_id, setting_key, setting_value, is_public, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [$id, 'registration.' . $key, $value, 0, $now, $now]);
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('公开会员编号与验证记录必须保留，请使用前向迁移。');
    }
}
