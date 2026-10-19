<?php

declare(strict_types=1);

use Phinx\Migration\{AbstractMigration, IrreversibleMigrationException};

/** 运维复用生命周期任务后的枚举含义通过前向迁移补齐。 */
final class DescribeInstanceOperationActions extends AbstractMigration
{
    /**
     * @return void 同步数据库实际枚举注释
     */
    public function up(): void
    {
        $this->table('service_lifecycle_tasks')
            ->changeColumn('action', 'string', ['limit' => 16, 'comment' => 'renew续费/suspend到期暂停/resume恢复/notice7、3、1提醒/power_on开机/power_off关机/reboot重启/reinstall重装/reset_password重置密码'])
            ->changeColumn('status', 'string', ['limit' => 24, 'default' => 'unpaid', 'comment' => 'unpaid待付款/queued待执行/processing处理中/waiting查单/succeeded成功/failed供应明确失败/attention待核查/cancelled作废'])
            ->update();
    }

    /**
     * @return void 任务语义不能回退
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('运维任务枚举已用于持久记录');
    }
}
