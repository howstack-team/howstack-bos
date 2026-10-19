<?php

declare(strict_types=1);

use Phinx\Migration\{AbstractMigration, IrreversibleMigrationException};

/** 单实例事务 Outbox、租约和独立加密凭据；不复制供应商密钥到普通快照。 */
final class CreateServiceProvisioning extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('service_provisioning_credentials')) {
            $this->table('service_provisioning_credentials', ['id' => false, 'primary_key' => ['id'], 'comment' => '订单初始登录凭据，独立加密并限时清理'])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'comment' => '初始凭据内部自增主键'])
                ->addColumn('order_id', 'integer', ['signed' => false, 'comment' => '所属订单内部主键'])
                ->addColumn('password_encrypted', 'text', ['null' => true, 'comment' => '加密初始密码，清理后为空，不进入订单或日志'])
                ->addColumn('expires_at', 'datetime', ['null' => true, 'comment' => '全部开通成功后四十八小时到期，未结束任务仍可使用'])
                ->addColumn('created_at', 'datetime', ['comment' => '凭据建立时间'])
                ->addIndex(['order_id'], ['unique' => true, 'name' => 'uk_provision_credential_order'])->create();
        }
        if (!$this->hasTable('service_provisioning_tasks')) {
            $this->table('service_provisioning_tasks', ['id' => false, 'primary_key' => ['id'], 'comment' => '与付款原子提交的逐实例开通任务，持久 Outbox'])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'comment' => '开通任务内部自增主键'])
                ->addColumn('order_id', 'integer', ['signed' => false, 'comment' => '所属订单内部主键'])
                ->addColumn('order_unit_id', 'integer', ['signed' => false, 'comment' => '订单实例金额单元，一单元只建立一个任务'])
                ->addColumn('service_id', 'integer', ['signed' => false, 'comment' => '首次关联的服务实例内部主键'])
                ->addColumn('request_key', 'string', ['limit' => 64, 'comment' => '固定供应请求标识，重试和查单不得改变'])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'queued', 'comment' => 'queued待执行/processing执行中/waiting待查单/succeeded成功/failed确认未创建/attention待人工核查'])
                ->addColumn('submitted_at', 'datetime', ['null' => true, 'comment' => '首次提交意图时间，有值后只查单，禁止盲目重复创建'])
                ->addColumn('attempts', 'integer', ['default' => 0, 'comment' => '领取执行次数，含查单'])
                ->addColumn('lease_token', 'string', ['limit' => 64, 'null' => true, 'comment' => '领取随机令牌，阻止过期执行者覆盖结果'])
                ->addColumn('lease_until', 'datetime', ['null' => true, 'comment' => '工作租约到期时间，过期可恢复查单'])
                ->addColumn('next_attempt_at', 'datetime', ['comment' => '下次执行时间，退避以秒计算'])
                ->addColumn('context', 'json', ['comment' => '创建时标准配置、销售版本、供应映射和政策，不含密码'])
                ->addColumn('connection_encrypted', 'text', ['null' => true, 'comment' => '固定供应连接加密配置，仅执行时解密，阻止编辑连接改变原请求目标'])
                ->addColumn('adapter_id', 'string', ['limit' => 64, 'default' => '', 'comment' => '原供应插件标识'])
                ->addColumn('package_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => '执行插件包摘要，变化时停止自动执行'])
                ->addColumn('connection_id', 'integer', ['signed' => false, 'null' => true, 'comment' => '原资源供应商连接主键'])
                ->addColumn('external_id', 'string', ['limit' => 128, 'null' => true, 'comment' => '供应商确认的实例标识，只能首次绑定不能替换'])
                ->addColumn('addresses', 'json', ['null' => true, 'comment' => '供应商返回的实例IP地址白名单'])
                ->addColumn('error_code', 'string', ['limit' => 64, 'null' => true, 'comment' => '稳定脱敏错误分类，不保存第三方原始响应'])
                ->addColumn('created_at', 'datetime', ['comment' => '任务建立时间'])
                ->addColumn('updated_at', 'datetime', ['comment' => '最近状态更新时间'])
                ->addIndex(['order_unit_id'], ['unique' => true, 'name' => 'uk_provision_order_unit'])
                ->addIndex(['service_id'], ['unique' => true, 'name' => 'uk_provision_service'])
                ->addIndex(['request_key'], ['unique' => true, 'name' => 'uk_provision_request'])
                ->addIndex(['connection_id', 'external_id'], ['unique' => true, 'name' => 'uk_provision_external'])
                ->addIndex(['order_id', 'id'], ['name' => 'idx_provision_order'])
                ->addIndex(['adapter_id', 'status'], ['name' => 'idx_provision_adapter'])
                ->addIndex(['status', 'next_attempt_at'], ['name' => 'idx_provision_due'])->create();
        }
    }
    public function down(): void
    {
        throw new IrreversibleMigrationException('开通任务关联真实资源，必须使用前向修复，禁止回滚删除');
    }
}
