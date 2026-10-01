<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 帮助分类支持两级，并移除分类不再使用的编码。 */
final class AddContentCategoryHierarchy extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('content_categories');
        if (!$table->hasColumn('parent_id')) {
            $table->addColumn('parent_id', 'integer', ['null' => true, 'signed' => false])
                ->addIndex(['parent_id'], ['name' => 'idx_content_category_parent'])
                ->update();
        }
        if ($table->hasIndex(['kind', 'code'])) {
            $table->removeIndex(['kind', 'code'])->update();
        }
        if ($table->hasColumn('code')) {
            $table->removeColumn('code')->update();
        }
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite' && !$table->hasForeignKey('parent_id')) {
            $prefix = (string) $this->getAdapter()->getOption('table_prefix');
            $table->addForeignKey('parent_id', 'content_categories', 'id', [
                'constraint' => $prefix . 'fk_content_category_parent', 'delete' => 'RESTRICT', 'update' => 'RESTRICT',
            ])->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('分类编码已移除，请使用数据库备份或前向修复迁移。');
    }
}
