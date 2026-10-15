<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 安装记录独立于供应连接，前向升级保留既有凭据及历史绑定。 */
final class CreateBusinessPluginInstallations extends AbstractMigration
{
    /** @return void 建立宿主管理记录及生命周期审计 */
    public function up(): void
    {
        if (!$this->hasTable('business_plugin_installations')) {
            $this->table('business_plugin_installations', ['comment' => '业务插件安装与启停记录'])
                ->addColumn('plugin_id', 'string', ['limit' => 64, 'comment' => '插件全局稳定标识，与分类目录一致'])
                ->addColumn('category', 'string', ['limit' => 24, 'comment' => '插件主类别，如provider、payment'])
                ->addColumn('version', 'string', ['limit' => 32, 'comment' => '已审核安装的代码包版本'])
                ->addColumn('package_hash', 'string', ['limit' => 64, 'comment' => '已审核包全部文件的SHA-256摘要'])
                ->addColumn('state', 'string', ['limit' => 24, 'comment' => '状态：installed已安装、enabled启用、draining停止接单、disabled停用、uninstalled卸载登记'])
                ->addColumn('revision', 'integer', ['default' => 1, 'comment' => '安装记录乐观锁版本，从1递增'])
                ->addColumn('created_at', 'datetime', ['comment' => '首次安装登记时间'])
                ->addColumn('updated_at', 'datetime', ['comment' => '最近生命周期变更时间'])
                ->addIndex(['plugin_id'], ['unique' => true])
                ->create();
        }
        if (!$this->hasTable('business_plugin_events')) {
            $this->table('business_plugin_events', ['comment' => '业务插件生命周期不可变审计'])
                ->addColumn('plugin_id', 'string', ['limit' => 64, 'comment' => '发生生命周期操作的插件稳定标识'])
                ->addColumn('action', 'string', ['limit' => 24, 'comment' => '动作：install、enable、drain、disable、upgrade、uninstall'])
                ->addColumn('from_state', 'string', ['limit' => 24, 'null' => true, 'comment' => '操作前状态；首次安装为NULL'])
                ->addColumn('to_state', 'string', ['limit' => 24, 'comment' => '操作完成后的安装状态'])
                ->addColumn('actor_id', 'integer', ['null' => true, 'comment' => '操作管理员标识；受控CLI命令为NULL'])
                ->addColumn('client_ip', 'string', ['limit' => 45, 'null' => true, 'comment' => '业务操作客户端IP快照；CLI为NULL，HTTP另有关联操作审计'])
                ->addColumn('client_ip_location', 'string', ['limit' => 128, 'null' => true, 'comment' => '业务操作IP地点快照；CLI为NULL，HTTP另有关联操作审计'])
                ->addColumn('package_hash', 'string', ['limit' => 64, 'comment' => '本次操作核准的包摘要，不包含配置秘密'])
                ->addColumn('created_at', 'datetime', ['comment' => '生命周期操作完成时间'])
                ->addIndex(['plugin_id', 'created_at'])
                ->create();
        }
        // Phinx 按连接配置处理前缀；显式变更默认主键注释，保留原类型属性。
        if ($this->getAdapter()->getOption('adapter') === 'mysql') {
            foreach (['business_plugin_installations' => '插件安装记录主键', 'business_plugin_events' => '插件生命周期审计主键'] as $name => $comment) {
                $this->table($name)->changeColumn('id', 'integer', ['identity' => true, 'comment' => $comment])->update();
            }
        }
    }

    /**
     * 审计与安装身份不得回退删除。
     * @return void
     * @throws IrreversibleMigrationException 仅允许前向修复
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('请使用前向修复保留插件安装与审计记录');
    }
}
