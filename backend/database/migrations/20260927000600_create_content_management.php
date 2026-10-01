<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 帮助文档与新闻公告共用结构化分类和内容记录。 */
final class CreateContentManagement extends AbstractMigration
{
    public function up(): void
    {
        $options = ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];
        if (!$this->hasTable('content_categories')) {
            $this->table('content_categories', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('kind', 'string', ['limit' => 16])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('code', 'string', ['limit' => 64])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['kind', 'code'], ['unique' => true, 'name' => 'uk_content_category_code'])
                ->create();
        }
        if (!$this->hasTable('content_entries')) {
            $this->table('content_entries', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('kind', 'string', ['limit' => 16])
                ->addColumn('category_id', 'integer', ['signed' => false])
                ->addColumn('title', 'string', ['limit' => 200])
                ->addColumn('summary', 'string', ['limit' => 500, 'default' => ''])
                ->addColumn('body', 'text')
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
                ->addColumn('published_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['kind', 'status', 'id'], ['name' => 'idx_content_kind_status'])
                ->addIndex(['category_id'], ['name' => 'idx_content_category'])
                ->create();
        }
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $prefix = (string) $this->getAdapter()->getOption('table_prefix');
            $entries = $this->table('content_entries');
            if (!$entries->hasForeignKey('category_id')) {
                $entries->addForeignKey('category_id', 'content_categories', 'id', [
                    'constraint' => $prefix . 'fk_content_category', 'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                ])->update();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('内容管理数据迁移不可逆，请使用前向修复迁移。');
    }
}
