<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreateMemberAuthentication extends AbstractMigration
{
    public function up(): void
    {
        $options = ['id' => false, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];
        if (!$this->hasTable('member_sessions')) {
            $this->table('member_sessions', $options + ['primary_key' => ['session_hash'], 'comment' => '会员可撤销登录会话许可，不保存原始JWT或凭据'])
                ->addColumn('session_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => '随机会话标识的SHA256摘要；退出删除即失效'])
                ->addColumn('member_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '所属会员内部主键'])
                ->addColumn('password_version', 'string', ['limit' => 64, 'null' => false, 'comment' => '签发时密码哈希的SHA256版本，用于密码变更失效'])
                ->addColumn('expires_at', 'datetime', ['null' => false, 'comment' => '会话截止时间；普通登录2小时，记住登录最长30天'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '会话建立时间'])
                ->addIndex(['member_id', 'expires_at'])->addIndex(['expires_at'])->create();
        }
        if (!$this->hasTable('member_auth_challenges')) {
            $this->table('member_auth_challenges', $options + ['primary_key' => ['challenge_id'], 'comment' => '会员登录及找回密码的一次性验证码，场景与浏览器绑定且只存摘要'])
                ->addColumn('challenge_id', 'string', ['limit' => 32, 'null' => false, 'comment' => '随机挑战标识，提供前端提交校验'])
                ->addColumn('purpose', 'string', ['limit' => 20, 'null' => false, 'comment' => 'login短信登录或password_reset密码重置；禁止混用'])
                ->addColumn('method', 'string', ['limit' => 8, 'null' => false, 'comment' => 'mobile手机号或email邮箱；登录只支持mobile'])
                ->addColumn('member_id', 'integer', ['signed' => false, 'null' => true, 'comment' => '发送时会员主键；未知或停用账号为NULL以避免响应枚举'])
                ->addColumn('password_version', 'string', ['limit' => 64, 'null' => false, 'comment' => '发送时密码版本摘要，密码变化后旧挑战失效'])
                ->addColumn('target_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => '用途及规范化接收地址的HMAC摘要'])
                ->addColumn('binding_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => '浏览器临时会话随机值的HMAC摘要'])
                ->addColumn('code_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => '挑战标识及验证码的HMAC摘要，不保存明文'])
                ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'comment' => 'pending待发送、active可校验、failed失败、used已使用或superseded已替换'])
                ->addColumn('attempts', 'integer', ['default' => 0, 'null' => false, 'comment' => '错误次数，最多5次后失效'])
                ->addColumn('expires_at', 'datetime', ['null' => false, 'comment' => '到期时间，创建后300秒'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '挑战创建时间'])
                ->addIndex(['purpose', 'target_hash', 'binding_hash'])->addIndex(['expires_at'])->create();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('认证会话与挑战结构只能使用前向迁移更新。');
    }
}
