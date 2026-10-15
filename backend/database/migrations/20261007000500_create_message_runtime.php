<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 消息及独立收件、投递、尝试记录；待投递记录同时是事务 Outbox。 */
final class CreateMessageRuntime extends AbstractMigration
{
    public function up(): void
    {
        $this->table('messages', ['comment' => '统一业务消息'])
            ->addColumn('event_key', 'string', ['limit' => 64, 'comment' => '业务事件幂等摘要'])
            ->addColumn('scene', 'string', ['limit' => 64, 'comment' => '固定消息场景编码'])
            ->addColumn('purpose', 'string', ['limit' => 24, 'comment' => '用途：notification、system、verification'])
            ->addColumn('business_type', 'string', ['limit' => 64, 'comment' => '业务对象类型'])
            ->addColumn('business_id', 'biginteger', ['comment' => '关联业务对象主键'])
            ->addColumn('created_at', 'datetime', ['comment' => '事件记录时间'])
            ->addIndex('event_key', ['unique' => true])->addIndex(['scene', 'created_at'])->create();
        $this->table('message_recipients', ['comment' => '消息接收对象及独立站内已读状态'])
            ->addColumn('message_id', 'integer', ['comment' => '逻辑消息主键'])
            ->addColumn('audience', 'string', ['limit' => 16, 'comment' => 'customer客户、admin管理员、robot机器人群'])
            ->addColumn('account_id', 'integer', ['default' => 0, 'comment' => '客户或管理员主键；群为0'])
            ->addColumn('target_key', 'string', ['limit' => 64, 'comment' => '账户或机器人渠道唯一标识'])
            ->addColumn('read_at', 'datetime', ['null' => true, 'comment' => '站内消息阅读时间；发送成功不等于已读'])
            ->addIndex(['message_id', 'audience', 'target_key'], ['unique' => true])
            ->addIndex(['audience', 'account_id', 'read_at'])->create();
        $this->table('message_deliveries', ['comment' => '各渠道发送任务及持久化待投递队列'])
            ->addColumn('recipient_id', 'integer', ['comment' => '消息接收对象主键'])
            ->addColumn('request_key', 'string', ['limit' => 36, 'comment' => '固定发送请求UUID；重试保持不变'])
            ->addColumn('channel', 'string', ['limit' => 24, 'comment' => '发送渠道编码'])
            ->addColumn('payload_encrypted', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'comment' => '收件地址及模板渲染结果加密快照；禁止写日志或Redis'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending', 'comment' => '投递状态：pending、processing、accepted、delivered、failed、unknown'])
            ->addColumn('attempts', 'integer', ['default' => 0, 'comment' => '实际领取次数'])
            ->addColumn('next_attempt_at', 'datetime', ['comment' => '允许再次投递时间'])
            ->addColumn('queue_after', 'datetime', ['comment' => '队列补投租约到期时间'])
            ->addColumn('lease_key', 'string', ['limit' => 64, 'default' => '', 'comment' => '发送进程领取令牌'])
            ->addColumn('lease_until', 'datetime', ['null' => true, 'comment' => '领取租约截止时间'])
            ->addColumn('error_code', 'string', ['limit' => 64, 'default' => '', 'comment' => '不含厂商响应正文的安全错误编码'])
            ->addColumn('created_at', 'datetime', ['comment' => '任务创建时间'])
            ->addColumn('updated_at', 'datetime', ['comment' => '任务状态更新时间'])
            ->addIndex(['recipient_id', 'channel'], ['unique' => true])->addIndex('request_key', ['unique' => true])
            ->addIndex(['status', 'next_attempt_at', 'queue_after'])->create();
        $this->table('message_delivery_attempts', ['comment' => '逐次发送结果与耗时；不保存收件地址、正文或凭据'])
            ->addColumn('delivery_id', 'integer', ['comment' => '投递任务主键'])
            ->addColumn('attempt_no', 'integer', ['comment' => '任务尝试序号'])
            ->addColumn('status', 'string', ['limit' => 24, 'comment' => '本次发送状态'])
            ->addColumn('error_code', 'string', ['limit' => 64, 'default' => '', 'comment' => '安全错误编码'])
            ->addColumn('duration_ms', 'integer', ['default' => 0, 'comment' => '发送耗时毫秒'])
            ->addColumn('created_at', 'datetime', ['comment' => '领取开始时间'])
            ->addColumn('finished_at', 'datetime', ['null' => true, 'comment' => '尝试结束时间'])
            ->addIndex(['delivery_id', 'attempt_no'], ['unique' => true])->create();
        if ($this->getAdapter()->getOption('adapter') === 'mysql') {
            foreach (['messages', 'message_recipients', 'message_deliveries', 'message_delivery_attempts'] as $table) {
                $this->table($table)->changeColumn('id', 'integer', ['identity' => true, 'comment' => '记录主键'])->update();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('消息流水不可回退删除，请使用前向修复');
    }
}
