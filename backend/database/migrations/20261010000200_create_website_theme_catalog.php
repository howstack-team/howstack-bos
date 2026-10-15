<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 登记既有启用主题；不改变官网指针、主题配置和修订。 */
final class CreateWebsiteThemeCatalog extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('website_themes')) {
            $this->table('website_themes', ['id' => false, 'primary_key' => ['theme_id'], 'comment' => '官网已安装主题目录及不可变包摘要'])
                ->addColumn('theme_id', 'string', ['limit' => 64, 'null' => false, 'comment' => '主题稳定标识，作为唯一主键'])
                ->addColumn('name', 'string', ['limit' => 80, 'null' => false, 'comment' => '主题展示名称'])
                ->addColumn('version', 'string', ['limit' => 32, 'null' => false, 'comment' => '已安装主题的语义版本'])
                ->addColumn('digest', 'string', ['limit' => 64, 'null' => false, 'comment' => '主题包SHA256摘要，用于固定私有模板与公开资源'])
                ->addColumn('installed_by', 'biginteger', ['signed' => false, 'null' => true, 'comment' => '安装管理员内部主键，系统内置主题为空'])
                ->addColumn('installed_at', 'datetime', ['null' => false, 'comment' => '主题安装时间'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '目录记录创建时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '目录记录最后修改时间'])->create();
        }
        $prefix = $this->getAdapter()->getOption('table_prefix');
        $website = $this->fetchRow('SELECT * FROM ' . $this->getAdapter()->quoteTableName($prefix . 'website_settings') . ' WHERE id = 1');
        $catalog = $this->getAdapter()->quoteTableName($prefix . 'website_themes');
        if ($website && !$this->fetchRow('SELECT theme_id FROM ' . $catalog . ' LIMIT 1')) {
            $now = date('Y-m-d H:i:s');
            $this->table('website_themes')->insert(['theme_id' => $website['active_theme_id'], 'name' => '默认主题',
                'version' => $website['active_theme_version'], 'digest' => $website['active_theme_digest'], 'installed_by' => null,
                'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now])->saveData();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('主题目录和已保存配置需保留，请使用前向修复。');
    }
}
