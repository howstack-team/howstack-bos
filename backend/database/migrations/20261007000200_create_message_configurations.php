<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 消息类型目录由核心代码声明；迁移只建配置表，不默认启用发送。 */
final class CreateMessageConfigurations extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('message_configurations')) {
            $this->table('message_configurations', ['comment' => '客户和管理员的消息模板及渠道策略配置'])
                ->addColumn('audience', 'string', ['limit' => 16, 'comment' => '接收对象：customer客户、admin管理员'])
                ->addColumn('message_type', 'string', ['limit' => 64, 'comment' => '核心定义的消息事件编码；不是自由新增的模板类型'])
                ->addColumn('configuration_encrypted', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'comment' => '该事件全部渠道的加密配置，包含机器人Webhook及加签密钥，禁止回显'])
                ->addColumn('revision', 'integer', ['default' => 1, 'comment' => '整条消息配置的乐观锁修订号，从1递增'])
                ->addColumn('created_at', 'datetime', ['comment' => '首次配置时间'])
                ->addColumn('updated_at', 'datetime', ['comment' => '最近保存时间'])
                ->addIndex(['audience', 'message_type'], ['unique' => true])
                ->create();
        }
        if ($this->getAdapter()->getOption('adapter') === 'mysql') {
            $this->table('message_configurations')->changeColumn('id', 'integer', ['identity' => true, 'comment' => '消息配置主键'])->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('消息配置仅允许前向修复，不得回退删除');
    }
}
