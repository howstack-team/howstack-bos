<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 将旧默认网站标题更新为新的系统正式名称，保留客户自定义标题。 */
final class RenameDefaultSiteTitle extends AbstractMigration
{
    /**
     * 仅替换旧默认标题，支持重复执行并沿用配置的表前缀。
     * @return void
     */
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $settingsTable = $this->getAdapter()->quoteTableName($prefix . 'system_settings');
        $this->execute(
            "UPDATE {$settingsTable} SET setting_value = ?, updated_at = ? WHERE setting_key = ? AND setting_value = ?",
            ['皓量云擎云业务运营系统', date('Y-m-d H:i:s'), 'site.title', '皓量云擎业务管理系统'],
        );
    }

    /**
     * 不回滚覆盖客户后来设置的标题，名称修订继续使用前向迁移。
     * @return void
     * @throws IrreversibleMigrationException 缺少原值快照，无法安全还原标题时抛出
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('默认网站标题更名不可逆，请使用前向修复迁移。');
    }
}
