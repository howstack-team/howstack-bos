<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreateOnlinePayments extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('online_payment_attempts')) {
            $t = $this->table('online_payment_attempts', ['id' => false, 'primary_key' => ['payment_id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_bin', 'comment' => '在线充值支付执行快照及查单租约；与核心充值、支付和账本共同关联']);
            $t->addColumn('payment_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '核心支付交易主键，一笔充值只创建一笔在线交易'])
                ->addColumn('member_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '付款会员内部主键，用于归属和幂等隔离'])
                ->addColumn('channel_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '发起时的支付通道主键'])
                ->addColumn('channel_revision', 'integer', ['signed' => false, 'null' => false, 'comment' => '发起时通道配置修订，后续不用当前商户配置覆盖'])
                ->addColumn('plugin_id', 'string', ['limit' => 128, 'null' => false, 'comment' => '审核通过的支付插件身份'])
                ->addColumn('package_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => '发起时审核包摘要，原交易只能使用匹配的受信包'])
                ->addColumn('configuration_encrypted', 'text', ['null' => false, 'comment' => '原商户配置加密快照，仅查单和验签执行时解密'])
                ->addColumn('request_key', 'string', ['limit' => 64, 'null' => false, 'comment' => '会员提供的充值意图幂等键，重复不另创建付款单'])
                ->addColumn('request_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => '通道及人民币整数分请求摘要，同键改金额拒绝'])
                ->addColumn('notify_token', 'string', ['limit' => 64, 'null' => false, 'comment' => '随机回调路由标识；仅定位原交易，不替代验签'])
                ->addColumn('notify_url', 'string', ['limit' => 1024, 'null' => false, 'comment' => '由系统官网地址生成的固定HTTPS回调快照'])
                ->addColumn('qr_code', 'string', ['limit' => 2048, 'null' => true, 'comment' => '经插件校验的支付二维码内容，非HTML'])
                ->addColumn('expires_at', 'datetime', ['null' => false, 'comment' => '付款展示截止时间，创建后15分钟，不能仅凭超时判定未支付'])
                ->addColumn('reconcile_until', 'datetime', ['null' => false, 'comment' => '自动查单截止时间，创建后7日；之后仍允许人工核查'])
                ->addColumn('lease_token', 'string', ['limit' => 64, 'null' => true, 'comment' => '执行租约随机令牌，旧执行结果不能覆盖新租约'])
                ->addColumn('lease_until', 'datetime', ['null' => true, 'comment' => '执行租约截止时间，最多120秒'])
                ->addColumn('next_check_at', 'datetime', ['null' => true, 'comment' => '下次自动查单时间，NULL表示终态或转人工'])
                ->addColumn('checked_at', 'datetime', ['null' => true, 'comment' => '最近查单或关单开始时间，用于5秒操作间隔'])
                ->addColumn('check_count', 'integer', ['default' => 0, 'null' => false, 'comment' => '查单次数，用于限速退避，不重发未知创建请求'])
                ->addColumn('last_error_code', 'string', ['limit' => 32, 'null' => true, 'comment' => '安全错误原因，不存凭据或上游原文'])
                ->addColumn('client_ip', 'string', ['limit' => 45, 'null' => true, 'comment' => '会员发起操作时经受信代理策略解析的IP快照'])
                ->addColumn('client_ip_location', 'string', ['limit' => 128, 'null' => true, 'comment' => '发起操作时IP位置快照，失败为未知'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '付款意图持久化时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '执行及核查状态更新时间'])
                ->addIndex(['member_id', 'request_key'], ['unique' => true, 'name' => 'online_payment_request_unique'])
                ->addIndex(['notify_token'], ['unique' => true])->addIndex(['next_check_at', 'lease_until'])->addIndex(['plugin_id'])
                ->create();
        }
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $table = $this->table('online_payment_attempts');
            if (!$table->hasForeignKey(['payment_id'])) $table->addForeignKey('payment_id', 'payment_transactions', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])->update();
        }
        $notifications = $this->table('payment_notifications');
        foreach (['client_ip' => [45, '验签通过的通知来源IP快照，不作为认证依据'], 'client_ip_location' => [128, '通知接收时IP位置快照，失败为未知'], 'content_hash' => [64, '通知原始正文SHA256摘要，不保存支付原始报文']] as $field => [$limit, $comment]) {
            if (!$notifications->hasColumn($field)) $notifications->addColumn($field, 'string', ['limit' => $limit, 'null' => true, 'comment' => $comment]);
        }
        $notifications->update();
        $this->table('payment_transactions')->changeColumn('channel_trade_no', 'string', ['limit' => 128, 'null' => true, 'comment' => '上游已验签交易号；原商户唯一收款标识，钱包支付可为空'])->update();
    }
    public function down(): void { throw new IrreversibleMigrationException('支付及资金快照只允许前向修复。'); }
}
