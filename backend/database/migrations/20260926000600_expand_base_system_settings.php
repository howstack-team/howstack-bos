<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 将基础设置扩展为固定的站点信息字段，并迁移旧站点名称。 */
final class ExpandBaseSystemSettings extends AbstractMigration
{
    /**
     * 新增缺失字段，将旧站点名称迁移为标题后删除旧键。
     * @return void
     */
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $categoryTable = $this->getAdapter()->quoteTableName($prefix . 'system_setting_categories');
        $settingsTable = $this->getAdapter()->quoteTableName($prefix . 'system_settings');
        $category = $this->fetchRow("SELECT id FROM {$categoryTable} WHERE code = 'base'");
        if ($category === false) {
            throw new RuntimeException('基础设置分类尚未初始化');
        }

        $categoryId = (int) $category['id'];
        $now = date('Y-m-d H:i:s');
        $legacyName = $this->fetchRow("SELECT setting_value FROM {$settingsTable} WHERE setting_key = 'site.name'");
        $title = $this->fetchRow("SELECT id, setting_value FROM {$settingsTable} WHERE setting_key = 'site.title'");
        if ($title !== false && trim((string) $title['setting_value']) === ''
            && $legacyName !== false && trim((string) $legacyName['setting_value']) !== '') {
            $this->execute(
                "UPDATE {$settingsTable} SET setting_value = ?, updated_at = ? WHERE id = ?",
                [trim((string) $legacyName['setting_value']), $now, (int) $title['id']],
            );
        }

        foreach ([
            'site.title',
            'site.subtitle',
            'site.keywords',
            'site.description',
            'site.url',
            'site.copyright',
            'site.icp_record',
            'site.public_security_record',
            'site.business_license',
        ] as $key) {
            $existing = $this->fetchRow("SELECT id FROM {$settingsTable} WHERE setting_key = '{$key}'");
            if ($existing === false) {
                $default = $key === 'site.title' && $legacyName !== false
                    ? trim((string) $legacyName['setting_value'])
                    : '';
                $this->execute(
                    "INSERT INTO {$settingsTable} (category_id, setting_key, setting_value, is_public, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                    [$categoryId, $key, $default, 1, $now, $now],
                );
                continue;
            }
            $this->execute("UPDATE {$settingsTable} SET category_id = ? WHERE id = ?", [$categoryId, (int) $existing['id']]);
        }

        $this->execute("DELETE FROM {$settingsTable} WHERE setting_key = 'site.name'");
    }

    /**
     * 禁止回滚时丢弃客户填写的备案和许可证数据。
     * @return void
     * @throws IrreversibleMigrationException 设置数据必须通过前向迁移修复时抛出
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('基础设置扩展迁移不可逆，请使用前向修复迁移。');
    }
}
