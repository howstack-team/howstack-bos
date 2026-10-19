<?php

declare(strict_types=1);

use Phinx\Migration\{AbstractMigration, IrreversibleMigrationException};

/** 只增加持久生命周期任务，不重写历史订单、实例或已执行迁移。 */
final class CreateServiceLifecycleTasks extends AbstractMigration
{
    /** @return void 可重复执行的前向扩展 */
    public function up(): void
    {
        if ($this->hasTable('service_lifecycle_tasks')) {
            return;
        }
        $this->table('service_lifecycle_tasks', ['id' => false, 'primary_key' => ['id'], 'comment' => '单实例续费、到期暂停和恢复的事务Outbox及租约'])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'comment' => '生命周期任务内部自增主键'])
            ->addColumn('service_id', 'integer', ['signed' => false, 'comment' => '原服务实例主键，续费不创建新实例'])
            ->addColumn('order_id', 'integer', ['signed' => false, 'null' => true, 'comment' => '续费订单主键，系统暂停恢复任务为空'])
            ->addColumn('action', 'string', ['limit' => 16, 'comment' => 'renew续费/suspend到期暂停/resume恢复/notice7、3、1到期提醒'])
            ->addColumn('request_key', 'string', ['limit' => 64, 'comment' => '固定幂等请求标识，未知结果只能原请求查单'])
            ->addColumn('expected_expires_at', 'datetime', ['comment' => '创建任务时的原到期时间，用于防止过期任务误暂停或重复续期'])
            ->addColumn('target_expires_at', 'datetime', ['comment' => '核定绝对到期时间，续费付款时确定，暂停恢复为当前到期时间'])
            ->addColumn('months', 'integer', ['default' => 0, 'comment' => '续费月份数，暂停恢复为零'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'unpaid', 'comment' => 'unpaid待付款/queued待执行/processing处理中/waiting待查单/succeeded成功/attention待核查/cancelled已作废'])
            ->addColumn('submitted_at', 'datetime', ['null' => true, 'comment' => '首次供应操作意图时间，非空后仅查单'])
            ->addColumn('attempts', 'integer', ['default' => 0, 'comment' => '含查单在内的领取次数，达到二十次转人工处理'])
            ->addColumn('lease_token', 'string', ['limit' => 64, 'null' => true, 'comment' => '随机租约令牌，过期执行者不能回写'])
            ->addColumn('lease_until', 'datetime', ['null' => true, 'comment' => '执行租约截止时间，超时恢复只查单'])
            ->addColumn('next_attempt_at', 'datetime', ['comment' => '下次领取时间，失败采用有上限的秒级退避'])
            ->addColumn('error_code', 'string', ['limit' => 64, 'null' => true, 'comment' => '脱敏错误分类，禁止保存供应商原始报文'])
            ->addColumn('created_at', 'datetime', ['comment' => '任务建立时间'])
            ->addColumn('updated_at', 'datetime', ['comment' => '最近状态变更时间'])
            ->addIndex(['order_id'], ['unique' => true, 'name' => 'uk_lifecycle_order'])
            ->addIndex(['request_key'], ['unique' => true, 'name' => 'uk_lifecycle_request'])
            ->addIndex(['service_id', 'action', 'expected_expires_at'], ['name' => 'idx_lifecycle_service_expiry'])
            ->addIndex(['status', 'next_attempt_at'], ['name' => 'idx_lifecycle_due'])->create();
    }

    /** @return void 不可逆交易历史只能前向修复 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('生命周期关联真实付费权益，禁止回滚删除');
    }
}
