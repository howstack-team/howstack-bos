<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 插件字段经 JSON 编码及加密后可能超过普通 TEXT，保留存量连接。 */
final class ExpandProviderConfigurationStorage extends AbstractMigration
{
    /** @return void 扩展连接配置容量 */
    public function up(): void
    {
        if ($this->getAdapter()->getOption('adapter') === 'sqlite') return;
        $this->table('provider_connections')
            ->changeColumn('credentials_encrypted', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->changeColumn('capabilities', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM])->update();
    }

    /** @return void @throws IrreversibleMigrationException 历史配置不可截断 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('不能缩小供应配置容量，请使用前向修复迁移。');
    }
}
