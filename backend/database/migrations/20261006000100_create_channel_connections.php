<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 六类基础通道配置；配置密文与插件安装记录独立保存。 */
final class CreateChannelConnections extends AbstractMigration
{
    /** @return void 创建配置表、分类锁及默认通道引用 */
    public function up(): void
    {
        if (!$this->hasTable('channel_connections')) {
            $this->table('channel_connections', ['comment' => '基础服务通道连接配置'])
                ->addColumn('category', 'string', ['limit' => 24, 'comment' => '通道类别：payment支付、sms短信、mail邮件、identity实名、storage存储、oauth登录'])
                ->addColumn('name', 'string', ['limit' => 128, 'comment' => '管理员自定义通道名称'])
                ->addColumn('plugin_id', 'string', ['limit' => 64, 'comment' => '已安装服务平台插件标识；创建后不更换'])
                ->addColumn('platform_snapshot', 'json', ['comment' => '创建或最近保存时的平台名称、版本、摘要和配置声明；不含秘密值'])
                ->addColumn('secret_keys', 'json', ['comment' => '曾声明为私密字段的键名，防止插件改为文本后泄漏历史值'])
                ->addColumn('configuration_encrypted', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'comment' => '完整连接配置加密密文，不得回显或写入业务日志'])
                ->addColumn('status', 'boolean', ['default' => 0, 'comment' => '配置启停状态：0禁用、1启用；不代表渠道业务执行成功'])
                ->addColumn('sort_order', 'integer', ['default' => 0, 'comment' => '展示排序，非负整数，默认从小到大'])
                ->addColumn('description', 'string', ['limit' => 500, 'default' => '', 'comment' => '管理员备注，不保存账号密码或密钥'])
                ->addColumn('revision', 'integer', ['default' => 1, 'comment' => '配置乐观锁版本，从1递增'])
                ->addColumn('deleted_at', 'datetime', ['null' => true, 'comment' => '归档时间；NULL表示在用配置，归档保留密文和历史身份'])
                ->addColumn('created_at', 'datetime', ['comment' => '配置创建时间'])
                ->addColumn('updated_at', 'datetime', ['comment' => '最近配置或状态修改时间'])
                ->addIndex(['category', 'deleted_at', 'sort_order'])
                ->addIndex(['plugin_id', 'status'])
                ->create();
        }
        if (!$this->hasTable('channel_category_settings')) {
            $this->table('channel_category_settings', ['comment' => '固定通道分类的默认选择及并发写入锁'])
                ->addColumn('category', 'string', ['limit' => 24, 'comment' => '固定通道类别，与channel_connections.category一致'])
                ->addColumn('default_channel_id', 'integer', ['null' => true, 'comment' => '同类别默认启用通道主键；NULL表示未指定，不自动切换渠道'])
                ->addIndex(['category'], ['unique' => true])
                ->create();
        }
        // MySQL DDL可能已提交；重试时补齐缺失分类，不覆盖已有默认连接。
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $settings = $this->getAdapter()->quoteTableName($prefix . 'channel_category_settings');
        $existing = array_column($this->fetchAll("SELECT category FROM {$settings}"), 'category');
        $missing = array_diff(['payment', 'sms', 'mail', 'identity', 'storage', 'oauth'], $existing);
        if ($missing !== []) {
            $this->table('channel_category_settings')->insert(array_map(
                static fn (string $category): array => ['category' => $category, 'default_channel_id' => null],
                array_values($missing),
            ))->saveData();
        }
        if ($this->getAdapter()->getOption('adapter') === 'mysql') {
            foreach (['channel_connections' => '通道配置主键', 'channel_category_settings' => '通道分类设置主键'] as $table => $comment) {
                $this->table($table)->changeColumn('id', 'integer', ['identity' => true, 'comment' => $comment])->update();
            }
        }
    }

    /** @return void @throws IrreversibleMigrationException 使用前向修复保留通道配置 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('通道配置仅允许前向修复，禁止回退删除');
    }
}
