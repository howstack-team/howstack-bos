<?php

declare(strict_types=1);

use Phinx\Migration\{AbstractMigration, IrreversibleMigrationException};

/** 扩展同一服务的互斥任务，不建立可绕开到期处理的第二条执行队列。 */
final class AddInstanceOperations extends AbstractMigration
{
    /**
     * @return void 可重复执行的前向迁移
     */
    public function up(): void
    {
        $table = $this->table('service_lifecycle_tasks');
        $columns = [
            'operation_context' => ['json', ['null' => true, 'comment' => '核定的运维镜像和登录资料，不含密码']],
            'password_encrypted' => ['text', ['null' => true, 'comment' => '运维密码短期密文，提交意图落库后立即清除']],
            'password_digest' => ['string', ['limit' => 255, 'null' => true, 'comment' => '密码慢哈希，仅用于同一幂等请求参数校验，不用于登录']],
            'credential_expires_at' => ['datetime', ['null' => true, 'comment' => '待执行密码有效期，超过二十四小时不再提交']],
            'provider_task_id' => ['string', ['limit' => 128, 'null' => true, 'comment' => '首次受理的供应任务标识，后续只查询此任务']],
        ];
        foreach ($columns as $name => [$type, $options]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        }
        $table->update();
        $table = $this->table('service_instances');
        foreach ([
            'power_status' => ['string', ['limit' => 16, 'null' => true, 'comment' => '最近确认的电源状态on/off/unknown，与订阅状态独立']],
            'power_checked_at' => ['datetime', ['null' => true, 'comment' => '供应商最近确认电源状态时间，不代表实时监控']],
            'installed_image' => ['json', ['null' => true, 'comment' => '最近确认重装的镜像ID名称与默认用户名端口，不改变购买快照']],
            'initial_password_disabled_at' => ['datetime', ['null' => true, 'comment' => '提交重装或重置密码后禁用原初始密码查看的时间']],
        ] as $name => [$type, $options]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        }
        $table->update();
    }

    /**
     * @return void 不删除操作历史
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('实例操作历史与敏感凭据状态禁止回滚删除');
    }
}
