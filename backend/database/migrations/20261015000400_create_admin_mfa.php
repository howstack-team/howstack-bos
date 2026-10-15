<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreateAdminMfa extends AbstractMigration
{
    public function up(): void
    {
        $users = $this->table('admin_users');
        if (!$users->hasColumn('auth_version')) $users->addColumn('auth_version', 'integer', ['null' => false, 'default' => 0, 'signed' => false, 'comment' => '管理员认证修订；绑定、解绑或重置MFA后递增，使旧JWT及登录挑战失效'])->update();
        $options = ['id' => false, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_bin'];
        if (!$this->hasTable('admin_mfa_credentials')) {
            $this->table('admin_mfa_credentials', $options + ['primary_key' => ['admin_user_id'], 'comment' => '管理员TOTP验证器及一次性恢复码；密钥加密保存'])
                ->addColumn('admin_user_id', 'integer', ['null' => false, 'signed' => false, 'comment' => '管理员内部主键；每人一条MFA凭据'])
                ->addColumn('secret_encrypted', 'text', ['null' => true, 'comment' => '已启用TOTP共享密钥的密文；NULL表示未开启'])
                ->addColumn('pending_encrypted', 'text', ['null' => true, 'comment' => '待确认绑定的TOTP密钥密文，不可用于登录'])
                ->addColumn('setup_hash', 'string', ['limit' => 64, 'null' => true, 'comment' => '一次性绑定随机标识的SHA256摘要'])
                ->addColumn('setup_expires_at', 'datetime', ['null' => true, 'comment' => '待绑定密钥到期时间，创建后10分钟'])
                ->addColumn('last_counter', 'biginteger', ['null' => false, 'default' => -1, 'comment' => '最后已消费TOTP时间步；30秒一步，拒绝重放及倒退'])
                ->addColumn('recovery_hashes', 'text', ['null' => false, 'comment' => '一次性恢复码SHA256摘要的JSON数组；明文只在生成时返回'])
                ->addColumn('enabled_at', 'datetime', ['null' => true, 'comment' => '绑定验证器成功时间；未绑定为NULL'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '凭据记录创建时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '凭据最近更新时间'])->create();
        }
        if (!$this->hasTable('admin_mfa_challenges')) {
            $this->table('admin_mfa_challenges', $options + ['primary_key' => ['token_hash'], 'comment' => '管理员通过密码后的MFA登录挑战；不是后台访问凭证'])
                ->addColumn('token_hash', 'string', ['null' => false, 'limit' => 64, 'comment' => '浏览器HttpOnly临时Cookie随机值的SHA256摘要'])
                ->addColumn('admin_user_id', 'integer', ['null' => false, 'signed' => false, 'comment' => '已通过密码校验的管理员内部主键'])
                ->addColumn('credential_version', 'string', ['null' => false, 'limit' => 64, 'comment' => '创建时密码哈希与认证修订的摘要；修改密码或MFA后失效'])
                ->addColumn('remember', 'boolean', ['null' => false, 'default' => false, 'comment' => '第二步验证成功后是否持久保存登录Cookie'])
                ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0, 'comment' => '已尝试校验次数，最多5次；包括恢复码'])
                ->addColumn('expires_at', 'datetime', ['null' => false, 'comment' => '挑战截止时间，密码通过后5分钟'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '密码校验通过时间'])
                ->addIndex(['admin_user_id'])->addIndex(['expires_at'])->create();
        }
    }
    public function down(): void { throw new IrreversibleMigrationException('管理员MFA安全数据仅通过前向迁移更新。'); }
}
