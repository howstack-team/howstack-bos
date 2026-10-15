<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 增加数据库文件分类，原有对象地址、状态及引用保持不变。 */
final class AddDatabaseFileCategory extends AbstractMigration
{
    /** 更新分类注释并回填历史SQL/SQLite文件；重复执行不覆盖其他分类。
     * @return void
     */
    public function up(): void
    {
        $this->table('file_assets')->changeColumn('type', 'string', [
            'limit' => 16,
            'null' => false,
            'comment' => '文件分类：image图片document文档video视频audio音频database数据库other其他',
        ])->update();

        $prefix = $this->getAdapter()->getOption('table_prefix') ?? '';
        $table = $this->getAdapter()->quoteTableName($prefix . 'file_assets');
        $this->execute("UPDATE {$table} SET type='database' WHERE type IN ('document','other') AND LOWER(extension) IN ('sql','db','sqlite','sqlite3')");
    }

    /** 分类回退必须通过前向迁移，避免丢失用户已保存的数据库分类。
     * @return void
     * @throws IrreversibleMigrationException 不支持逆向回退
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('请通过前向迁移恢复数据库文件分类。');
    }
}
