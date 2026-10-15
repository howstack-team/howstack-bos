<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 销售配置可包含多组资源，避免 MySQL 普通 TEXT 的 64 KiB 限制截断版本快照。 */
final class ExpandCloudConfigurationStorage extends AbstractMigration
{
    /**
     * 扩展 JSON 文档字段容量；SQLite TEXT 本身无该容量限制。
     * @return void
     */
    public function up(): void
    {
        if ($this->getAdapter()->getOption('adapter') === 'sqlite') return;
        foreach (['offers' => 'draft', 'offer_versions' => 'snapshot', 'provider_bindings' => 'mapping'] as $table => $column) {
            $this->table($table)->changeColumn($column, 'text', ['limit' => MysqlAdapter::TEXT_LONG])->update();
        }
    }

    /**
     * 禁止缩小已保存快照的容量。
     * @return void
     * @throws IrreversibleMigrationException 回滚可能截断历史配置
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('不能缩小历史配置容量，请使用前向修复迁移。');
    }
}
