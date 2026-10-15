<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 官网连接私有状态与持久安装任务；独立于客户业务订单和资金。 */
final class CreateOfficialPlatformTables extends AbstractMigration
{
    /** @return void 以可重试的前向迁移创建表和中文字段说明 */
    public function up(): void
    {
        if (!$this->hasTable('official_platform_state')) {
            $this->table('official_platform_state', ['comment' => '官网连接及商业授权私有状态，单安装实例使用固定记录'])
                ->addColumn('encrypted_state', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG, 'comment' => 'APP_KEY加密的账号凭证、配对状态和签名授权证书，禁止公开序列化'])
                ->addColumn('updated_at', 'datetime', ['comment' => '私有连接状态最后变更时间'])
                ->create();
        }
        if (!$this->hasTable('software_installation_jobs')) {
            $this->table('software_installation_jobs', ['comment' => '本机系统更新及应用安装持久任务，官网登记不代表本机安装成功'])
                ->addColumn('request_id', 'string', ['limit' => 36, 'comment' => '客户端幂等任务UUID，重复请求复用'])
                ->addColumn('kind', 'string', ['limit' => 16, 'comment' => '任务类型：system系统更新、application应用安装或更新'])
                ->addColumn('action', 'string', ['limit' => 16, 'comment' => '固定动作：install安装、update更新'])
                ->addColumn('target_code', 'string', ['limit' => 120, 'comment' => '系统软件或应用稳定标识，不接受文件路径'])
                ->addColumn('version_id', 'integer', ['null' => true, 'comment' => '官网应用目标版本标识；系统更新为NULL'])
                ->addColumn('from_version', 'string', ['limit' => 64, 'comment' => '任务受理时本机版本'])
                ->addColumn('target_version', 'string', ['limit' => 64, 'comment' => '管理员明确确认的目标版本'])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending', 'comment' => 'pending等待、running执行中、awaiting_restart待重启、succeeded成功、failed失败、recovery_required需人工恢复'])
                ->addColumn('phase', 'string', ['limit' => 24, 'default' => 'pending', 'comment' => '下载、预检、备份、文件安装、迁移、重启等执行阶段'])
                ->addColumn('manifest_json', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG, 'comment' => '已验证的目标元数据；不保存官网访问令牌或下载凭证'])
                ->addColumn('message', 'string', ['limit' => 255, 'default' => '', 'comment' => '可向管理员展示的安全执行说明，不含原始异常或密钥'])
                ->addColumn('error_code', 'string', ['limit' => 64, 'null' => true, 'comment' => '稳定失败原因标识，禁止存储原始请求报文'])
                ->addColumn('backup_key', 'string', ['limit' => 36, 'null' => true, 'comment' => '私有备份引用UUID，不向浏览器暴露服务器绝对路径'])
                ->addColumn('report_status', 'string', ['limit' => 16, 'default' => 'none', 'comment' => '官网任务回报状态：none不适用、pending待回报、reported已回报'])
                ->addColumn('lease_until', 'datetime', ['null' => true, 'comment' => '执行租约截止时间，崩溃恢复先核查文件阶段，不盲目重放'])
                ->addColumn('actor_id', 'integer', ['null' => true, 'comment' => '发起任务的管理员标识；受控CLI为NULL'])
                ->addColumn('client_ip', 'string', ['limit' => 45, 'null' => true, 'comment' => '发起操作客户端IP快照；系统或CLI为NULL'])
                ->addColumn('client_ip_location', 'string', ['limit' => 128, 'null' => true, 'comment' => '发起操作IP地点快照；系统或CLI为NULL'])
                ->addColumn('created_at', 'datetime', ['comment' => '任务受理时间'])
                ->addColumn('updated_at', 'datetime', ['comment' => '任务状态最后变更时间'])
                ->addIndex(['request_id'], ['unique' => true])
                ->addIndex(['status', 'created_at'])
                ->create();
        }
        if ($this->getAdapter()->getOption('adapter') === 'mysql') {
            foreach (['official_platform_state', 'software_installation_jobs'] as $table) {
                $this->table($table)->changeColumn('id', 'integer', ['identity' => true, 'comment' => '记录主键'])->update();
            }
        }
    }

    /** @return never @throws IrreversibleMigrationException 私有身份与审计任务仅允许前向修复 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('请以前向迁移保留官网连接与安装任务');
    }
}
