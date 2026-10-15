<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 增加人机验证分类锁；保留其他类别和已有默认通道。 */
final class AddCaptchaChannelCategory extends AbstractMigration
{
    /** @return void 幂等补充分类及中文注释 */
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $table = $this->getAdapter()->quoteTableName($prefix . 'channel_category_settings');
        if ($this->fetchRow("SELECT id FROM {$table} WHERE category = 'captcha'") === false) {
            $this->table('channel_category_settings')->insert(['category' => 'captcha', 'default_channel_id' => null])->saveData();
        }
        $this->table('channel_connections')->changeColumn('category', 'string', [
            'limit' => 24,
            'comment' => '通道类别：payment支付、sms短信、mail邮件、identity实名、storage存储、oauth登录、captcha人机验证',
        ])->update();
    }

    /** @return never 禁止回退删除正在使用的通道类别 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('人机验证通道类别仅允许前向修复');
    }
}
