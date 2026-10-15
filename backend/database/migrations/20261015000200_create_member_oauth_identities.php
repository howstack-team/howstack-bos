<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreateMemberOauthIdentities extends AbstractMigration
{
    public function up(): void
    {
        $options = ['id' => false, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_bin'];
        if (!$this->hasTable('member_oauth_identities')) {
            $this->table('member_oauth_identities', $options + ['comment' => '会员第三方身份关联；平台、应用和身份区分大小写，不保存上游令牌'])
                ->addColumn('provider', 'string', ['limit' => 128, 'null' => false, 'comment' => '第三方登录插件标识'])
                ->addColumn('app_id', 'string', ['limit' => 128, 'null' => false, 'comment' => '上游应用标识，不同应用的身份不可自动合并'])
                ->addColumn('subject', 'string', ['limit' => 128, 'null' => false, 'comment' => '上游已验证OpenID等稳定身份，不使用昵称或邮箱识别'])
                ->addColumn('member_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '关联的会员内部主键'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '经过平台账号验证后建立关联的时间'])
                ->addIndex(['provider', 'app_id', 'subject'], ['unique' => true, 'name' => 'oauth_identity_unique'])
                ->addIndex(['member_id', 'provider', 'app_id'], ['unique' => true, 'name' => 'oauth_member_app_unique'])->create();
        }
        if (!$this->hasTable('member_oauth_pending')) {
            $table = $this->table('member_oauth_pending', $options + ['primary_key' => ['ticket_hash'], 'comment' => '待关联第三方身份；随机票据仅存摘要，十分钟有效且一次消费']);
            $table->addColumn('ticket_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => 'HttpOnly关联票据的SHA256摘要'])
                ->addColumn('binding_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => '原浏览器绑定随机值的SHA256摘要'])
                ->addColumn('provider', 'string', ['limit' => 128, 'null' => false, 'comment' => '经上游验证的第三方登录插件标识'])
                ->addColumn('app_id', 'string', ['limit' => 128, 'null' => false, 'comment' => '经上游验证的应用标识'])
                ->addColumn('subject', 'string', ['limit' => 128, 'null' => false, 'comment' => '经上游验证的稳定身份，不保存上游访问令牌'])
                ->addColumn('channel_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '本次授权的通道内部主键'])
                ->addColumn('channel_revision', 'integer', ['signed' => false, 'null' => false, 'comment' => '授权时配置修订，关联时变化则拒绝'])
                ->addColumn('remember_me', 'boolean', ['default' => false, 'null' => false, 'comment' => '原授权请求是否选择记住登录'])
                ->addColumn('attempts', 'integer', ['default' => 0, 'null' => false, 'comment' => '错误账号验证次数，达到5次后不可关联'])
                ->addColumn('expires_at', 'datetime', ['null' => false, 'comment' => '关联截止时间，创建后600秒'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '上游身份认证完成时间'])
                ->addIndex(['expires_at'])->addIndex(['binding_hash'])->create();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('身份关联含安全与账号数据，请使用前向迁移。');
    }
}
