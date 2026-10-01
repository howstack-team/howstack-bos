<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 为既有设置项增加分类，初始化基础和安全设置并保留客户现有值。
 */
final class AddSystemSettingCategories extends AbstractMigration
{
    /**
     * 扩展两表结构并补全缺失设置；兼容迁移中断后的重复执行。
     * @return void
     */
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        if (!$this->hasTable('system_setting_categories')) {
            $this->table('system_setting_categories', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('code', 'string', ['limit' => 32])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('sort_order', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['code'], ['unique' => true, 'name' => 'uk_setting_category_code'])
                ->create();
        }

        $settings = $this->table('system_settings');
        if (!$settings->hasColumn('category_id')) {
            $settings->addColumn('category_id', 'integer', ['signed' => false, 'null' => true])->update();
        }
        if (!$settings->hasIndexByName('idx_setting_category')) {
            $settings->addIndex(['category_id'], ['name' => 'idx_setting_category'])->update();
        }
        // SQLite 仅用于本地迁移测试；Phinx 0.16 的前缀外键复核有缺陷，生产 MySQL 仍使用物理外键。
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite' && !$settings->hasForeignKey('category_id')) {
            $settings->addForeignKey('category_id', 'system_setting_categories', 'id', [
                'constraint' => $prefix . 'fk_setting_category',
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])->update();
        }

        $categoryTable = $this->getAdapter()->quoteTableName($prefix . 'system_setting_categories');
        $settingsTable = $this->getAdapter()->quoteTableName($prefix . 'system_settings');
        $now = date('Y-m-d H:i:s');
        $configuredTtl = filter_var(getenv('JWT_TTL'), FILTER_VALIDATE_INT);
        $sessionMinutes = max(5, min(1440, (int) ceil(($configuredTtl > 0 ? $configuredTtl : 7200) / 60)));

        foreach ([
            'base' => ['基础设置', 10, [
                'site.name' => '皓量云擎业务管理系统',
                'site.title' => '',
                'site.description' => '',
                'site.url' => trim((string) (getenv('APP_URL') ?: '')),
                'site.copyright' => '',
            ]],
            'security' => ['安全设置', 20, [
                'security.session_expire' => (string) $sessionMinutes,
                'security.login_attempts' => '10',
                'security.login_window' => '60',
            ]],
        ] as $code => [$name, $sortOrder, $values]) {
            $category = $this->fetchRow("SELECT id FROM {$categoryTable} WHERE code = '{$code}'");
            if ($category === false) {
                $this->execute(
                    "INSERT INTO {$categoryTable} (code, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
                    [$code, $name, $sortOrder, $now, $now],
                );
                $category = $this->fetchRow("SELECT id FROM {$categoryTable} WHERE code = '{$code}'");
            }
            if ($category === false) {
                throw new RuntimeException('系统设置分类初始化失败');
            }
            $categoryId = (int) $category['id'];
            foreach ($values as $key => $value) {
                // 只补全分类归属；已安装系统的值、公开标记及修改时间都保持不变。
                $existing = $this->fetchRow("SELECT id FROM {$settingsTable} WHERE setting_key = '{$key}'");
                if ($existing !== false) {
                    $this->execute("UPDATE {$settingsTable} SET category_id = ? WHERE id = ?", [$categoryId, (int) $existing['id']]);
                    continue;
                }
                $this->execute(
                    "INSERT INTO {$settingsTable} (category_id, setting_key, setting_value, is_public, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                    [$categoryId, $key, $value, $code === 'base' ? 1 : 0, $now, $now],
                );
            }
        }
    }

    /**
     * 禁止回滚时丢弃客户已保存的分类和配置，使用前向迁移修复。
     * @return void
     * @throws IrreversibleMigrationException 分类及设置数据需要保留时抛出
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('系统设置分类迁移不可逆，请使用前向修复迁移。');
    }
}
