<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 内置 SMTP 首次登记及邮件幂等记录；不创建邮箱账号，也不覆盖已存在插件状态。 */
final class AddSmtpMailDelivery extends AbstractMigration
{
    /** @return void 前向迁移 */
    public function up(): void
    {
        if (!$this->hasTable('mail_delivery_attempts')) {
            $this->table('mail_delivery_attempts', ['comment' => '邮件发送幂等与结果记录，不保存正文、邮箱或凭据'])
                ->addColumn('request_key', 'string', ['limit' => 36, 'comment' => '调用方UUID幂等键；相同键永不重复发送'])
                ->addColumn('channel_id', 'integer', ['comment' => '发送时邮件通道主键'])
                ->addColumn('channel_revision', 'integer', ['comment' => '发送时通道配置版本'])
                ->addColumn('actor_id', 'integer', ['null' => true, 'comment' => '测试发送管理员主键；系统业务发送为NULL'])
                ->addColumn('purpose', 'string', ['limit' => 16, 'comment' => '用途：test测试或business业务'])
                ->addColumn('payload_hash', 'string', ['limit' => 64, 'comment' => '请求摘要，用于拒绝同幂等键不同内容；不保存正文'])
                ->addColumn('status', 'string', ['limit' => 16, 'comment' => 'sending发送中、accepted服务器接收、failed明确失败、unknown结果未知'])
                ->addColumn('created_at', 'datetime', ['comment' => '首次发起时间'])
                ->addColumn('updated_at', 'datetime', ['comment' => '结果记录时间'])
                ->addIndex(['request_key'], ['unique' => true])
                ->addIndex(['channel_id', 'purpose', 'created_at'])
                ->create();
        }
        if ($this->getAdapter()->getOption('adapter') === 'mysql') {
            $this->table('mail_delivery_attempts')->changeColumn('id', 'integer', ['identity' => true, 'comment' => '邮件发送记录主键'])->update();
        }
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $table = $this->getAdapter()->quoteTableName($prefix . 'business_plugin_installations');
        if ($this->fetchRow("SELECT id FROM {$table} WHERE plugin_id = 'smtp'") !== false) {
            return;
        }
        // 固定随发行包交付的内置目录；与包注册器相同的文件摘要算法。
        // 不引用会随版本变化的应用类，不重登记已有插件，不处理外部下载包。
        $folder = dirname(__DIR__, 2) . '/plugins/mail/smtp';
        $manifest = json_decode(file_get_contents($folder . '/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($manifest['id'] !== 'smtp' || $manifest['category'] !== 'mail') {
            throw new RuntimeException('内置 SMTP 包身份无效');
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('内置 SMTP 包不能包含符号链接');
            }
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($folder) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        $hash = hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
        $now = date('Y-m-d H:i:s');
        $this->table('business_plugin_installations')->insert([
            'plugin_id' => 'smtp', 'category' => 'mail', 'version' => $manifest['version'],
            'package_hash' => $hash, 'state' => 'enabled', 'revision' => 2, 'created_at' => $now, 'updated_at' => $now,
        ])->saveData();
        $this->table('business_plugin_events')->insert([
            ['plugin_id' => 'smtp', 'action' => 'install', 'from_state' => null, 'to_state' => 'installed', 'actor_id' => null, 'package_hash' => $hash, 'created_at' => $now],
            ['plugin_id' => 'smtp', 'action' => 'enable', 'from_state' => 'installed', 'to_state' => 'enabled', 'actor_id' => null, 'package_hash' => $hash, 'created_at' => $now],
        ])->saveData();
    }

    /** @return never @throws IrreversibleMigrationException 发送历史与通道只允许前向修复 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('不得删除邮件发送幂等记录或覆盖内置通道状态');
    }
}
