<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 数据库文件归入其他分类，仅修改分类，不删除文件或改写通道绑定。 */
final class RemoveDatabaseFileCategory extends AbstractMigration
{
    /** 合并分类并更新中文字段注释，允许中断后重试。
     * @return void
     */
    public function up(): void
    {
        $prefix = $this->getAdapter()->getOption('table_prefix') ?? '';
        $table = $this->getAdapter()->quoteTableName($prefix . 'file_assets');
        $this->execute("UPDATE {$table} SET type='other' WHERE type='database'");
        $this->table('file_assets')->changeColumn('type', 'string', [
            'limit' => 16,
            'null' => false,
            'comment' => '文件分类：image图片document文档video视频audio音频other其他',
        ])->update();
    }

    /** 回退通过前向迁移处理，不能重新解释已合并的其他文件。
     * @return void
     * @throws IrreversibleMigrationException 不支持逆向回退
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('请通过前向迁移恢复分类。');
    }
}
